<?php

namespace Icinga\Module\Proactiveha\Worker;

use ipl\Sql\Connection;
use Icinga\Application\Logger;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Integration\BusinessProcessReader;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config;
use Icinga\Module\Proactiveha\Util\ClusterSafety;
use Icinga\Module\Proactiveha\Util\EventLogger;
use ipl\Stdlib\Filter;

class SyncAgent
{
    private $db;
    private $logger;
    private $bpReader;
    private $clients = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->logger = new EventLogger($db);
        $this->bpReader = new BusinessProcessReader();
    }

    public function run()
    {
        $runId = $this->startRun();
        $processed = 0;
        $failed = 0;

        $this->logger->log('info', 'sync_start', "Sync run $runId started");

        try {
            $mappings = Mapping::on($this->db)
                ->with('vcenter')
                ->filter(Filter::equal('enabled', 1))
                ->execute();

            foreach ($mappings as $mapping) {
                if (!$mapping->vcenter || !$mapping->vcenter->enabled) {
                    continue;
                }

                $this->logger->setContext($mapping->id, $mapping->vcenter_id);

                try {
                    $state = $this->bpReader->getNodeState($mapping->bp_config_name, $mapping->bp_node_name);
                    if ($state === null) {
                        throw new \RuntimeException("BP node {$mapping->bp_node_name} not found");
                    }

                    $vsphereState = StateTranslator::toVsphereState($state['state']);
                    if ($vsphereState === null) {
                        $this->logger->log('debug', 'sync_state_ignored', "State {$state['state_name']} maps to no vSphere state");
                        continue;
                    }

                    $this->updateDesiredState($mapping, $state['state'], $state['state_name'], $vsphereState);
                    $processed++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->log('error', 'sync_evaluate_failed', $e->getMessage());
                }
            }

            $pushFailed = $this->pushPendingStates();
            $this->finishRun($runId, 'success', $processed, $failed, "Sync completed, $pushFailed push(es) failed");
        } catch (\Exception $e) {
            $this->logger->log('error', 'sync_fatal', $e->getMessage());
            $this->finishRun($runId, 'failed', $processed, $failed, $e->getMessage());
            throw $e;
        } finally {
            $this->logger->setContext();
        }
    }

    private function pushPendingStates()
    {
        $pending = State::on($this->db)
            ->filter(Filter::equal('push_status', 'pending'))
            ->execute();

        $byVcenter = [];
        foreach ($pending as $item) {
            $mapping = Mapping::on($this->db)
                ->with('vcenter')
                ->filter(Filter::equal('id', $item->mapping_id))
                ->first();

            if (!$mapping) {
                $this->fail($item, 'Mapping not found for pending state', true);
                continue;
            }

            if (!$mapping->vcenter) {
                $this->fail($item, 'vCenter not found for mapping ' . $mapping->id, true);
                continue;
            }

            if (empty($mapping->vsphere_host_moid)) {
                $this->fail($item, 'Host MOID not configured for mapping ' . $mapping->id, true);
                continue;
            }

            $byVcenter[$mapping->vcenter_id][] = ['item' => $item, 'mapping' => $mapping];
        }

        $pushFailed = 0;

        foreach ($byVcenter as $vcenterId => $entries) {
            $vcenter = $entries[0]['mapping']->vcenter;

            try {
                $client = $this->getClient($vcenter);

                if (!$client->isHealthUpdateManagerAvailable()) {
                    throw new \RuntimeException('HealthUpdateManager not available');
                }

                $providerId = $vcenter->provider_key;
                if (empty($providerId)) {
                    $providerId = $client->registerProvider();
                    $this->db->update('proactiveha_vcenter', [
                        'provider_key' => $providerId,
                        'provider_registered' => 1,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id = ?' => $vcenterId]);
                }

                foreach ($entries as $entry) {
                    $item = $entry['item'];
                    $mapping = $entry['mapping'];

                    $this->logger->setContext($mapping->id, $vcenterId);
                    try {
                        if (!$client->hasMonitoredEntity($providerId, $mapping->vsphere_host_moid)) {
                            $client->addMonitoredEntities($providerId, [$mapping->vsphere_host_moid]);
                            $this->logger->log('info', 'sync_add_monitored_entity', sprintf(
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
                                $this->logger->log('warning', 'sync_push_blocked_by_cluster_safety', $check['reason']);
                                continue;
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

                        $this->logger->log('info', 'sync_push', "Pushed {$item->vsphere_state} for {$mapping->vsphere_host_name}");
                    } catch (\Exception $e) {
                        $pushFailed++;
                        $this->fail($item, $e->getMessage(), false);
                    }
                }
            } catch (\Exception $e) {
                foreach ($entries as $entry) {
                    $pushFailed++;
                    $this->logger->setContext($entry['mapping']->id, $vcenterId);
                    $this->fail($entry['item'], $e->getMessage(), false);
                }
            }
        }

        return $pushFailed;
    }

    private function updateDesiredState($mapping, $state, $stateName, $vsphereState)
    {
        $current = State::on($this->db)
            ->filter(Filter::equal('mapping_id', $mapping->id))
            ->first();

        $now = date('Y-m-d H:i:s');

        if ($current && $current->desired_state == $state) {
            $this->db->update('proactiveha_state', [
                'last_evaluated' => $now,
                'updated_at' => $now
            ], ['mapping_id = ?' => $mapping->id]);
            return;
        }

        $data = [
            'desired_state' => $state,
            'desired_state_name' => $stateName,
            'vsphere_state' => $vsphereState,
            'push_status' => 'pending',
            'push_attempts' => 0,
            'retry_at' => null,
            'last_error' => null,
            'last_evaluated' => $now,
            'updated_at' => $now
        ];

        if ($current) {
            $this->db->update('proactiveha_state', $data, ['mapping_id = ?' => $mapping->id]);
            $this->logger->log('info', 'sync_state_changed', "State changed to $stateName");
        } else {
            $data['mapping_id'] = $mapping->id;
            $this->db->insert('proactiveha_state', $data);
            $this->logger->log('info', 'sync_state_created', "Initial state set to $stateName");
        }
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
            $this->logger->log('error', 'sync_push_failed_final', $message);
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
            $this->logger->log('warning', 'sync_push_failed_retry', $message, ['retry_at' => $retryAt]);
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

    private function startRun()
    {
        $id = bin2hex(random_bytes(8));
        $this->db->insert('proactiveha_sync_run', [
            'id' => $id,
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'running',
            'mappings_processed' => 0,
            'mappings_failed' => 0
        ]);
        return $id;
    }

    private function finishRun($id, $status, $processed, $failed, $message)
    {
        $this->db->update('proactiveha_sync_run', [
            'finished_at' => date('Y-m-d H:i:s'),
            'status' => $status,
            'mappings_processed' => $processed,
            'mappings_failed' => $failed,
            'message' => $message
        ], ['id = ?' => $id]);
    }
}
