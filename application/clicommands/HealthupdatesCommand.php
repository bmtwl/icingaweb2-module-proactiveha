<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\ProviderId;
use ipl\Stdlib\Filter;

class HealthupdatesCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function listAction()
    {
        $id = $this->params->getRequired('id');

        $db = $this->getDb();

        $vcenter = Vcenter::on($db)
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$vcenter) {
            $this->fail('vCenter not found');
        }

        $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
        $client = new VcenterClient([
            'url'        => $vcenter->url,
            'username'   => $vcenter->username,
            'password'   => $password,
            'verify_ssl' => (bool) $vcenter->verify_ssl
        ]);

        $client->connect();

        if (empty($vcenter->provider_key)) {
            $this->fail('No provider registered for this vCenter');
        }

        $updates = $client->queryHealthUpdates($vcenter->provider_key);

        if (empty($updates)) {
            echo "No health updates found for provider " . ProviderId::toUuid($vcenter->provider_key) . ".\n";
            return;
        }

        foreach ($updates as $update) {
            $entity = $update->entity ?? null;
            $moid = $entity instanceof \Icinga\Module\Proactiveha\Client\ManagedObjectReference
                ? $entity->_
                : (is_object($entity) ? ($entity->_ ?? 'unknown') : 'unknown');

            echo sprintf(
                "entity=%s status=%s component=%s id=%s remediation=%s\n",
                $moid,
                $update->status ?? 'unknown',
                $update->healthUpdateInfoId ?? 'unknown',
                $update->id ?? 'unknown',
                $update->remediation ?? ''
            );
        }
    }
}
