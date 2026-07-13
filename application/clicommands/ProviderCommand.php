<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use Icinga\Module\Proactiveha\Util\ProviderId;
use ipl\Stdlib\Filter;

class ProviderCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function registerAction()
    {
        $id = $this->params->getRequired('id');
        $name = $this->params->get('name', 'Icinga Proactive HA');
        $componentType = $this->params->get('component-type', 'Power');
        $componentId = $this->params->get('component-id', 'Power');
        $description = $this->params->get('description', 'Icinga Proactive HA host health');

        $client = $this->createClient($id);
        $providerId = $client->registerProvider($name, $componentType, $componentId, $description);

        $this->getDb()->update('proactiveha_vcenter', [
            'provider_key'        => $providerId,
            'provider_registered' => 1,
            'updated_at'          => date('Y-m-d H:i:s')
        ], ['id = ?' => $id]);

        echo "Provider registered: " . ProviderId::toUuid($providerId) . "\n";
    }

    public function unregisterAction()
    {
        $id = $this->params->getRequired('id');
        $providerId = ProviderId::normalize($this->params->getRequired('provider-id'));

        $client = $this->createClient($id);
        $client->unregisterProvider($providerId);

        $this->getDb()->update('proactiveha_vcenter', [
            'provider_key'        => null,
            'provider_registered' => 0,
            'updated_at'          => date('Y-m-d H:i:s')
        ], ['id = ?' => $id]);

        echo "Provider unregistered: " . ProviderId::toUuid($providerId) . "\n";
    }

    public function listAction()
    {
        $id = $this->params->getRequired('id');

        $client = $this->createClient($id);
        $providers = $client->queryProviderList();

        if (empty($providers)) {
            echo "No providers registered.\n";
            return;
        }

        foreach ($providers as $providerId) {
            $name = $client->queryProviderName($providerId);
            echo ProviderId::toUuid($providerId) . "\t$name\n";
        }
    }

    public function infosAction()
    {
        $id = $this->params->getRequired('id');
        $providerId = ProviderId::normalize($this->params->getRequired('provider-id'));

        $client = $this->createClient($id);
        $infos = $client->queryHealthUpdateInfos($providerId);

        foreach ($infos as $info) {
            echo sprintf("%s (%s): %s\n", $info->id, $info->componentType, $info->description);
        }
    }

    public function addentitiesAction()
    {
        $id = $this->params->getRequired('id');
        $providerId = ProviderId::normalize($this->params->getRequired('provider-id'));
        $moids = explode(',', $this->params->getRequired('moids'));

        $client = $this->createClient($id);
        $client->addMonitoredEntities($providerId, $moids);

        echo "Added " . count($moids) . " monitored entity(ies).\n";
    }

    public function removeentitiesAction()
    {
        $id = $this->params->getRequired('id');
        $providerId = ProviderId::normalize($this->params->getRequired('provider-id'));
        $moids = explode(',', $this->params->getRequired('moids'));

        $client = $this->createClient($id);
        $client->removeMonitoredEntities($providerId, $moids);

        echo "Removed " . count($moids) . " monitored entity(ies).\n";
    }

    public function hasentityAction()
    {
        $id = $this->params->getRequired('id');
        $providerId = ProviderId::normalize($this->params->getRequired('provider-id'));
        $moid = $this->params->getRequired('moid');

        $client = $this->createClient($id);
        $has = $client->hasMonitoredEntity($providerId, $moid);

        echo $has ? "Host is monitored.\n" : "Host is NOT monitored.\n";
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
}
