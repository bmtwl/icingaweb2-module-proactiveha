<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Integration\BusinessProcessReader;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use Icinga\Module\Proactiveha\Worker\StateTranslator;
use ipl\Stdlib\Filter;

class TestMappingCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function indexAction()
    {
        $id = $this->params->getRequired('id');
        $push = (bool) $this->params->get('push', false);
        $db = $this->getDb();

        $mapping = Mapping::on($db)
            ->with('vcenter')
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$mapping) {
            $this->fail('Mapping not found');
        }

        $logger = new EventLogger($db);
        $logger->setContext($mapping->id, $mapping->vcenter_id);

        try {
            $bpReader = new BusinessProcessReader();
            $state = $bpReader->getNodeState($mapping->bp_config_name, $mapping->bp_node_name);

            if ($state === null) {
                throw new \RuntimeException('Business Process node not found');
            }

            $vsphereState = StateTranslator::toVsphereState($state['state']);
            $logger->log('info', 'cli_test_bp', sprintf('BP state: %s -> %s', $state['state_name'], $vsphereState));

            $vcenter = $mapping->vcenter;
            $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
            $client = new VcenterClient([
                'url' => $vcenter->url,
                'username' => $vcenter->username,
                'password' => $password,
                'verify_ssl' => (bool) $vcenter->verify_ssl
            ]);

            $client->connect();
            $logger->log('info', 'cli_test_connect', 'SOAP session established');

            if (!$client->isHealthUpdateManagerAvailable()) {
                throw new \RuntimeException('HealthUpdateManager not available');
            }

            $moid = $mapping->vsphere_host_moid;
            if (empty($moid)) {
                $moid = $client->findHostMoid($mapping->vsphere_host_name);
                $logger->log('info', 'cli_test_moid', sprintf('Resolved MOID: %s', $moid));
            }

            $providerId = $vcenter->provider_key;
            if (empty($providerId)) {
                $providerId = $client->registerProvider();
                $db->update('proactiveha_vcenter', [
                    'provider_key' => $providerId,
                    'provider_registered' => 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id = ?' => $vcenter->id]);
                $logger->log('info', 'cli_test_register', sprintf('Registered provider: %s', $providerId));
            }

            if ($push) {
                $client->postHealthUpdates($providerId, $moid, 'Power', $vsphereState);
                $logger->log('info', 'cli_test_push', sprintf('Pushed %s for %s', $vsphereState, $moid));
                echo "Pushed $vsphereState for $moid\n";
            } else {
                echo "Would push $vsphereState for $moid (use --push to actually send)\n";
            }
        } catch (\Exception $e) {
            $logger->log('error', 'cli_test_error', $e->getMessage());
            $this->fail($e->getMessage());
        }
    }
}
