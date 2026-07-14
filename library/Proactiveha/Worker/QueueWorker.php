<?php

namespace Icinga\Module\Proactiveha\Worker;

use ipl\Sql\Connection;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Util\Config;
use Icinga\Module\Proactiveha\Util\ClusterSafety;
use Icinga\Module\Proactiveha\Util\EventLogger;
use ipl\Stdlib\Filter;

class QueueWorker
{
    private $db;
    private $logger;
    private $clients = [];
    private $running = true;
    private $once = false;

    public function __construct(Connection $db, $once = false)
    {
        $this->db = $db;
        $this->logger = new EventLogger($db);
        $this->once = (bool) $once;
    }

    public function run()
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }

        $this->logger->log('info', 'worker_start', 'Queue worker started');

        $this->db->update('proactiveha_state', [
            'push_status' => 'pending',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['push_status = ?' => 'in_progress']);

        do {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                $this->cycle();
            } catch (\Exception $e) {
                $this->logger->log('error', 'worker_cycle_exception', $e->getMessage());
            }

            if (!$this->once) {
                sleep(Config::workerInterval());
            }
        } while (!$this->once && $this->running);

        $this->logger->log('info', 'worker_stop', 'Queue worker stopped');
    }

    public function shutdown()
    {
        $this->running = false;
    }

    private function cycle()
    {
        $now = date('Y-m-d H:i:s');
        $queue = State::on($this->db)
            ->filter(Filter::equal('push_status', 'pending'))
            ->orderBy('updated_at', SORT_ASC)
            ->execute();

        foreach ($queue as $item) {
            if ($item->retry_at !== null && $item->retry_at > $now) {
                continue;
            }

            $this->process($item);
        }
    }

    private function process($item)
    {
        $this->db->update('proactiveha_state', [
            'push_status' => 'in_progress',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id = ?' => $item->id]);

        $mapping = Mapping::on($this->db)
            ->with('vcenter')
            ->filter(Filter::equal('id', $item->mapping_id))
            ->first();

        if (!$mapping) {
            $this->fail($item, 'Mapping not found for pending state', true);
            return;
        }

        if (!$mapping->vcenter) {
            $this->fail($item, 'vCenter not found for mapping ' . $mapping->id, true);
            return;
        }

        if (empty($mapping->vsphere_host_moid)) {
            $this->fail($item, 'Host MOID not configured for mapping ' . $mapping->id, true);
            return;
        }

        $vcenter = $mapping->vcenter;
        $this->logger->setContext($mapping->id, $vcenter->id);

        try {
            $client = $this->getClient($vcenter);

            if (!$client->isHealthUpdateManagerAvailable()) {
                $this->fail($item, 'vCenter does not expose HealthUpdateManager', true);
                return;
            }

            $providerId = $vcenter->provider_key;
            if (empty($providerId)) {
                $providerId = $client->registerProvider();
                $this->db->update('proactiveha_vcenter', [
                    'provider_key' => $providerId,
                    'provider_registered' => 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id = ?' => $vcenter->id]);
            }

            if (!$client->hasMonitoredEntity($providerId, $mapping->vsphere_host_moid)) {
                $client->addMonitoredEntities($providerId, [$mapping->vsphere_host_moid]);
                $this->logger->log('info', 'worker_add_monitored_entity', sprintf(
                    'Added %s as monitored entity for provider %s',
                    $mapping->vsphere_host_moid,
                    $providerId
                ));
            }

            if ($item->vsphere_state === 'red') {
                $safety = new ClusterSafety($this->db, $this->logger);
                $check = $safety->canPushRed($mapping);
                if (!$check['allowed']) {
                    $this->db->update('proactiveha_state', [
                        'push_status' => 'blocked',
                        'last_error'  => $check['reason'],
                        'updated_at'  => date('Y-m-d H:i:s')
                    ], ['id = ?' => $item->id]);
                    $this->logger->log('warning', 'push_blocked_by_cluster_safety', $check['reason']);
                    return;
                }
            }

            $client->postHealthUpdates($providerId, $mapping->vsphere_host_moid, 'Power', $item->vsphere_state);
            $this->db->update('proactiveha_state', [
                'push_status' => 'synced',
                'last_pushed' => date('Y-m-d H:i:s'),
                'push_attempts' => 0,
                'retry_at' => null,
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id = ?' => $item->id]);
            $this->logger->log('info', 'push_success', "Pushed {$item->vsphere_state} for {$mapping->vsphere_host_name}");
        } catch (\Exception $e) {
            $this->fail($item, $e->getMessage(), false);
        }

        $this->logger->setContext();
    }

    private function fail($item, $message, $final)
    {
        $attempts = $item->push_attempts + 1;
        $maxAttempts = Config::maxAttempts();

        if ($final || $attempts >= $maxAttempts) {
            $this->db->update('proactiveha_state', [
                'push_status' => 'failed',
                'push_attempts' => $attempts,
                'last_error' => $message,
                'retry_at' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id = ?' => $item->id]);
            $this->logger->log('error', 'push_failed_final', $message);
        } else {
            $backoff = [10, 30, 60, 120, 300, 600];
            $delay = $backoff[min($attempts, count($backoff) - 1)];
            $retryAt = date('Y-m-d H:i:s', time() + $delay);
            $this->db->update('proactiveha_state', [
                'push_status' => 'pending',
                'push_attempts' => $attempts,
                'last_error' => $message,
                'retry_at' => $retryAt,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id = ?' => $item->id]);
            $this->logger->log('warning', 'push_failed_retry', $message, ['retry_at' => $retryAt]);
        }
    }

    private function getClient($vcenter)
    {
        if (!isset($this->clients[$vcenter->id])) {
            $password = PasswordEncryptor::decrypt($vcenter->password, Config::keyFile());
            $this->clients[$vcenter->id] = new VcenterClient([
                'url' => $vcenter->url,
                'username' => $vcenter->username,
                'password' => $password,
                'verify_ssl' => (bool) $vcenter->verify_ssl
            ]);
            $this->clients[$vcenter->id]->connect();
        }

        return $this->clients[$vcenter->id];
    }
}
