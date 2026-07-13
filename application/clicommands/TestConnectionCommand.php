<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use ipl\Stdlib\Filter;

class TestConnectionCommand extends Command
{
    use Database;

    public function indexAction()
    {
        $id = $this->params->getRequired('id');
        $db = $this->getDb();

        $logger = new EventLogger($db);
        $logger->setContext(null, $id);

        $vcenter = Vcenter::on($db)
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$vcenter) {
            $this->fail('vCenter not found');
        }

        try {
            $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
            $client = new VcenterClient([
                'url' => $vcenter->url,
                'username' => $vcenter->username,
                'password' => $password,
                'verify_ssl' => (bool) $vcenter->verify_ssl
            ]);

            $client->connect();
            $logger->log('info', 'cli_test_connect', 'SOAP session established');

            if ($client->isHealthUpdateManagerAvailable()) {
                $logger->log('info', 'cli_test_hum', 'HealthUpdateManager is available');
            } else {
                $logger->log('warning', 'cli_test_hum', 'HealthUpdateManager not found');
            }

            $hosts = $client->listHosts();
            $logger->log('info', 'cli_test_hosts', sprintf('Found %d host(s)', count($hosts)));

            echo "Connection OK. Found " . count($hosts) . " host(s).\n";
        } catch (\Exception $e) {
            $logger->log('error', 'cli_test_error', $e->getMessage());
            $this->fail($e->getMessage());
        }
    }
}
