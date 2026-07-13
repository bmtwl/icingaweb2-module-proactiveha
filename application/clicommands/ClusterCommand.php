<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Model\Cluster;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use Icinga\Module\Proactiveha\Util\ProviderId;
use ipl\Stdlib\Filter;

class ClusterCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function listAction()
    {
        $id = $this->params->getRequired('id');
        $client = $this->createClient($id);

        $clusters = $client->listClusters();
        if (empty($clusters)) {
            echo "No clusters found.\n";
            return;
        }

        foreach ($clusters as $cluster) {
            echo sprintf("%s\t%s\n", $cluster['moid'], $cluster['name']);
        }
    }

    public function registerAction()
    {
        $id = $this->params->getRequired('id');
        $moid = $this->params->getRequired('moid');

        $client = $this->createClient($id);
        $providerId = $this->ensureProvider($id, $client);

        $hosts = $client->listClusterHosts($moid);
        $hostMoIds = array_filter(array_column($hosts, 'moid'));
        if (!empty($hostMoIds)) {
            $client->addMonitoredEntities($providerId, $hostMoIds);
        }

        $this->persistCluster($id, $moid, true);

        echo "Provider registered for cluster $moid. Added " . count($hostMoIds) . " monitored host(s).\n";
    }

    public function unregisterAction()
    {
        $id = $this->params->getRequired('id');
        $moid = $this->params->getRequired('moid');

        $client = $this->createClient($id);
        $providerId = $this->ensureProvider($id, $client);

        $client->unregisterProvider($providerId);

        $this->persistCluster($id, $moid, false);

        echo "Provider unregistered for cluster $moid.\n";
    }

    public function statusAction()
    {
        $id = $this->params->getRequired('id');
        $moid = $this->params->getRequired('moid');

        $client = $this->createClient($id);
        $config = $client->getClusterProactiveHaConfig($moid);

        if (!$config) {
            echo "Proactive HA not configured on cluster $moid.\n";
            return;
        }

        echo "Enabled: " . ($config['enabled'] ? 'Yes' : 'No') . "\n";
        echo "Mode: " . $config['mode'] . "\n";
        echo "Moderate: " . $config['moderateRemediation'] . "\n";
        echo "Severe: " . $config['severeRemediation'] . "\n";
        echo "Providers: " . implode(', ', $config['providers']) . "\n";
    }

    private function createClient($id)
    {
        $db = $this->getDb();

        $vcenter = Vcenter::on($db)
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$vcenter) {
            $this->fail('vCenter not found');
        }

        $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());

        return new VcenterClient([
            'url'        => $vcenter->url,
            'username'   => $vcenter->username,
            'password'   => $password,
            'verify_ssl' => (bool) $vcenter->verify_ssl
        ]);
    }

    private function ensureProvider($vcenterId, VcenterClient $client)
    {
        $db = $this->getDb();

        $vcenter = Vcenter::on($db)
            ->filter(Filter::equal('id', $vcenterId))
            ->first();

        $providerId = $vcenter->provider_key;
        if (empty($providerId)) {
            $providerId = $client->registerProvider();
            $db->update('proactiveha_vcenter', [
                'provider_key'        => $providerId,
                'provider_registered' => 1,
                'updated_at'          => date('Y-m-d H:i:s')
            ], ['id = ?' => $vcenterId]);
        }

        return $providerId;
    }

    private function persistCluster($vcenterId, $moid, $enabled)
    {
        $db = $this->getDb();

        $existing = Cluster::on($db)
            ->filter(Filter::all(
                Filter::equal('vcenter_id', $vcenterId),
                Filter::equal('mo_id', $moid)
            ))
            ->first();

        $data = [
            'vcenter_id'           => $vcenterId,
            'mo_id'                => $moid,
            'name'                 => $moid,
            'enabled'              => 1,
            'provider_enabled'     => $enabled ? 1 : 0,
            'updated_at'           => date('Y-m-d H:i:s')
        ];

        if ($enabled) {
            $data['last_enabled_at'] = date('Y-m-d H:i:s');
        } else {
            $data['last_disabled_at'] = date('Y-m-d H:i:s');
        }

        if ($existing) {
            $db->update('proactiveha_cluster', $data, ['id = ?' => $existing->id]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('proactiveha_cluster', $data);
        }
    }
}
