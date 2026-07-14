<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Util\ClusterSafety;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use ipl\Stdlib\Filter;

class ForcestateCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function indexAction()
    {
        $id = (int) $this->params->getRequired('id');
        $stateName = strtolower($this->params->getRequired('state'));
        $push = (bool) $this->params->get('push', false);

        $validStates = ['green' => 0, 'yellow' => 1, 'red' => 2];
        if (!array_key_exists($stateName, $validStates)) {
            $this->fail('State must be one of: ' . implode(', ', array_keys($validStates)));
        }

        $icingaState = $validStates[$stateName];
        $stateLabel = $this->stateLabel($icingaState);

        $db = $this->getDb();

        $logger = new EventLogger($db);
        $logger->setContext($id, null);

        $mapping = Mapping::on($db)
            ->with('vcenter')
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$mapping) {
            $this->fail('Mapping not found');
        }

        $logger->setContext($mapping->id, $mapping->vcenter_id);

        $existing = State::on($db)
            ->filter(Filter::equal('mapping_id', $id))
            ->first();

        $now = date('Y-m-d H:i:s');
        $data = [
            'desired_state'      => $icingaState,
            'desired_state_name' => $stateLabel,
            'vsphere_state'      => $stateName,
            'push_status'        => 'pending',
            'push_attempts'      => 0,
            'retry_at'           => null,
            'last_error'         => null,
            'last_evaluated'     => $now,
            'updated_at'         => $now
        ];

        if ($existing) {
            $db->update('proactiveha_state', $data, ['mapping_id = ?' => $id]);
        } else {
            $data['mapping_id'] = $id;
            $db->insert('proactiveha_state', $data);
        }

        echo "Forced mapping $id to $stateName ($stateLabel), push_status=pending\n";

        $logger->log('info', 'force_state', "Forced state to $stateName for {$mapping->vsphere_host_name}");

        if (!$push) {
            return;
        }

        if ($stateName === 'red') {
            $safety = new ClusterSafety($db, $logger);
            $check = $safety->canPushRed($mapping);
            if (!$check['allowed']) {
                $db->update('proactiveha_state', [
                    'push_status' => 'blocked',
                    'last_error'  => $check['reason'],
                    'updated_at'  => $now
                ], ['mapping_id = ?' => $id]);
                $logger->log('warning', 'force_state_blocked', $check['reason']);
                $this->fail($check['reason']);
            }
        }

        if (empty($mapping->vsphere_host_moid)) {
            $this->fail('Mapping has no vsphere_host_moid');
        }

        try {
            $password = PasswordEncryptor::decrypt($mapping->vcenter->password, ModuleConfig::keyFile());
            $client = new VcenterClient([
                'url'        => $mapping->vcenter->url,
                'username'   => $mapping->vcenter->username,
                'password'   => $password,
                'verify_ssl' => (bool) $mapping->vcenter->verify_ssl
            ]);
            $client->connect();

            $providerId = $mapping->vcenter->provider_key;
            if (empty($providerId)) {
                $providerId = $client->registerProvider();
                $db->update('proactiveha_vcenter', [
                    'provider_key'        => $providerId,
                    'provider_registered' => 1,
                    'updated_at'          => date('Y-m-d H:i:s')
                ], ['id = ?' => $mapping->vcenter_id]);
            }

            if (!$client->hasMonitoredEntity($providerId, $mapping->vsphere_host_moid)) {
                $client->addMonitoredEntities($providerId, [$mapping->vsphere_host_moid]);
            }

            echo "\n=== SOAP REQUEST BEFORE PostHealthUpdates ===\n";
            echo $client->getLastRequest() ?: '(none)';
            echo "\n=== END SOAP REQUEST BEFORE ===\n\n";

            $client->postHealthUpdates($providerId, $mapping->vsphere_host_moid, 'Power', $stateName);

            echo "\n=== SOAP REQUEST AFTER PostHealthUpdates ===\n";
            echo $client->getLastRequest() ?: '(none)';
            echo "\n=== END SOAP REQUEST AFTER ===\n\n";

            echo "\n=== SOAP RESPONSE AFTER PostHealthUpdates ===\n";
            echo $client->getLastResponse() ?: '(none)';
            echo "\n=== END SOAP RESPONSE AFTER ===\n\n";

            $db->update('proactiveha_state', [
                'push_status' => 'synced',
                'last_pushed' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s')
            ], ['mapping_id = ?' => $id]);

            $logger->log('info', 'force_state_push', "Pushed $stateName for {$mapping->vsphere_host_name}");
            echo "Pushed $stateName to vCenter for {$mapping->vsphere_host_name}\n";
        } catch (\Exception $e) {
            $db->update('proactiveha_state', [
                'push_status' => 'failed',
                'last_error'  => $e->getMessage(),
                'updated_at'  => date('Y-m-d H:i:s')
            ], ['mapping_id = ?' => $id]);
            $logger->log('error', 'force_state_push_failed', $e->getMessage());

            echo "\n=== SOAP REQUEST ON ERROR ===\n";
            echo $client->getLastRequest() ?: '(none)';
            echo "\n=== END SOAP REQUEST ON ERROR ===\n\n";

            echo "\n=== SOAP RESPONSE ON ERROR ===\n";
            echo $client->getLastResponse() ?: '(none)';
            echo "\n=== END SOAP RESPONSE ON ERROR ===\n\n";

            $this->fail($e->getMessage());
        }
    }

    private function stateLabel($state)
    {
        switch ($state) {
            case 0: return 'OK';
            case 1: return 'WARNING';
            case 2: return 'CRITICAL';
            default: return 'UNKNOWN';
        }
    }
}
