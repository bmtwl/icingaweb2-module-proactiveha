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

class HealthCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function pushAction()
    {
        $id = $this->params->getRequired('id');
        $providerId = ProviderId::normalize($this->params->getRequired('provider-id'));
        $moid = $this->params->getRequired('moid');
        $status = $this->params->getRequired('status');
        $componentId = $this->params->get('component-id', 'Power');
        $remediation = $this->params->get('remediation');

        if (!in_array($status, ['green', 'yellow', 'red'], true)) {
            $this->fail('Status must be green, yellow or red');
        }

        $client = $this->createClient($id);
        $client->postHealthUpdates($providerId, $moid, $componentId, $status, $remediation);

        echo "Pushed $status health update for $moid.\n";
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
