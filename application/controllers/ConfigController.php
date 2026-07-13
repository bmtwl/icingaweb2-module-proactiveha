<?php

namespace Icinga\Module\Proactiveha\Controllers;

use Icinga\Application\Config;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Common\RestrictionFilter;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Forms\Config\DatabaseConfigForm;
use Icinga\Module\Proactiveha\Forms\ConfirmDeleteForm;
use Icinga\Module\Proactiveha\Forms\VcenterForm;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use Icinga\Module\Proactiveha\Util\ProviderId;
use Icinga\Module\Proactiveha\Web\Widget\TestResults;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class ConfigController extends CompatController
{
    use Database;
    use RestrictionFilter;

    public function init()
    {
        $this->assertPermission('proactiveha/admin');
    }

    public function indexAction()
    {
        $query = Vcenter::on($this->getDb())->orderBy('name');
        $this->applyRestrictions($query);

        $this->view->title = $this->translate('vCenter Connections');
        $this->addContent(new \Icinga\Module\Proactiveha\Web\Widget\VcenterTable($query->execute()));
    }

    public function addAction()
    {
        $form = new VcenterForm($this->getDb());
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/config');
        }

        $this->view->title = $this->translate('Add vCenter Connection');
        $this->addContent($form);
    }

    public function editAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        if ($id === null) {
            throw new NotFoundError($this->translate('vCenter not found'));
        }

        $vcenter = Vcenter::on($this->getDb())
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$vcenter) {
            throw new NotFoundError($this->translate('vCenter not found'));
        }

        $form = new VcenterForm($this->getDb(), $id);
        $form->populate([
            'name'       => $vcenter->name,
            'url'        => $vcenter->url,
            'username'   => $vcenter->username,
            'verify_ssl' => (bool) $vcenter->verify_ssl,
            'enabled'    => (bool) $vcenter->enabled
        ]);
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/config');
        }

        $this->view->title = $this->translate('Edit vCenter Connection');
        $this->addContent($form);
    }

    public function deleteAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        if ($id === null) {
            throw new NotFoundError($this->translate('vCenter not found'));
        }

        $form = new ConfirmDeleteForm(
            $this->getDb(),
            'proactiveha_vcenter',
            Url::fromPath('proactiveha/config')->getAbsoluteUrl(),
            $id
        );
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/config');
        }

        $this->view->title = $this->translate('Delete vCenter Connection');
        $this->addContent($form);
    }

    public function testAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;

        $vcenter = Vcenter::on($this->getDb())
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$vcenter) {
            throw new NotFoundError($this->translate('vCenter not found'));
        }

        $logger = new EventLogger($this->getDb());
        $logger->setContext(null, $vcenter->id);

        $client = null;
        $steps  = [];
        $hosts  = [];

        try {
            $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
            $client = new VcenterClient([
                'url'        => $vcenter->url,
                'username'   => $vcenter->username,
                'password'   => $password,
                'verify_ssl' => (bool) $vcenter->verify_ssl
            ]);

            $serviceContent = $client->connect();
            $steps[] = [
                'name'   => 'Connect',
                'status' => 'ok',
                'detail' => sprintf(
                    "Connected to %s\nAPI type: %s\nVersion: %s",
                    $vcenter->url,
                    $serviceContent->about->apiType ?? 'unknown',
                    $serviceContent->about->version ?? 'unknown'
                )
            ];

            if ($client->isHealthUpdateManagerAvailable()) {
                $steps[] = [
                    'name'   => 'HealthUpdateManager',
                    'status' => 'ok',
                    'detail' => 'HealthUpdateManager is available'
                ];
            } else {
                $steps[] = [
                    'name'   => 'HealthUpdateManager',
                    'status' => 'warning',
                    'detail' => 'HealthUpdateManager not found in ServiceContent'
                ];
            }

            try {
                $providerId = null;
                $providerUuid = null;

                if (!empty($vcenter->provider_key)) {
                    try {
                        $name = $client->queryProviderName($vcenter->provider_key);
                        $providerId = $vcenter->provider_key;
                        $providerUuid = ProviderId::toUuid($providerId);
                        $steps[] = [
                            'name'   => 'Verify Provider',
                            'status' => 'ok',
                            'detail' => "Using existing provider: $providerUuid ($name)"
                        ];
                    } catch (\Exception $e) {
                        $steps[] = [
                            'name'   => 'Verify Provider',
                            'status' => 'warning',
                            'detail' => 'Stored provider no longer valid, re-registering: ' . $e->getMessage()
                        ];
                    }
                }

                if ($providerId === null) {
                    $providerId = $client->registerProvider();
                    $providerUuid = ProviderId::toUuid($providerId);
                    $this->getDb()->update('proactiveha_vcenter', [
                        'provider_key'        => $providerId,
                        'provider_registered' => 1,
                        'updated_at'          => date('Y-m-d H:i:s')
                    ], ['id = ?' => $vcenter->id]);
                    $steps[] = [
                        'name'   => 'Register Provider',
                        'status' => 'ok',
                        'detail' => "Provider registered with ID: $providerUuid"
                    ];
                    $logger->log('info', 'vcenter_test_register', "Provider registered: $providerUuid");
                }

                $infos = $client->queryHealthUpdateInfos($providerId);
                $infoDetails = [];
                foreach ($infos as $info) {
                    $infoDetails[] = sprintf('%s (%s): %s', $info->id, $info->componentType, $info->description);
                }
                $steps[] = [
                    'name'   => 'Provider HealthUpdateInfos',
                    'status' => 'ok',
                    'detail' => implode("\n", $infoDetails) ?: 'No infos returned'
                ];
            } catch (\Exception $e) {
                $steps[] = [
                    'name'   => 'Register Provider',
                    'status' => 'error',
                    'detail' => $e->getMessage()
                ];
                $steps[] = [
                    'name'   => 'Register Provider Request',
                    'status' => 'error',
                    'detail' => $client->getLastRequest() ?? 'n/a'
                ];
                $steps[] = [
                    'name'   => 'Register Provider Response',
                    'status' => 'error',
                    'detail' => $client->getLastResponse() ?? 'n/a'
                ];
                $logger->log('error', 'vcenter_test_register', $e->getMessage());
            }

            try {
                $hosts = $client->listHosts();
                $steps[] = [
                    'name'   => 'List Hosts',
                    'status' => 'ok',
                    'detail' => sprintf('Found %d host(s)', count($hosts))
                ];
            } catch (\Exception $e) {
                $steps[] = [
                    'name'   => 'List Hosts',
                    'status' => 'error',
                    'detail' => $e->getMessage()
                ];
            }

            try {
                $clusters = $client->listClusters();
                $clusterDetails = [];
                foreach ($clusters as $cluster) {
                    $config = $client->getClusterProactiveHaConfig($cluster['moid']);
                    $enabled = $config && $config['enabled'] ? 'enabled' : 'disabled';
                    $clusterDetails[] = sprintf('%s (%s): Proactive HA %s', $cluster['name'], $cluster['moid'], $enabled);
                }
                $steps[] = [
                    'name'   => 'List Clusters',
                    'status' => 'ok',
                    'detail' => implode("\n", $clusterDetails) ?: 'No clusters found'
                ];
            } catch (\Exception $e) {
                $steps[] = [
                    'name'   => 'List Clusters',
                    'status' => 'warning',
                    'detail' => $e->getMessage()
                ];
            }

            $logger->log('info', 'vcenter_test', 'vCenter connection test succeeded');
        } catch (\Exception $e) {
            $steps[] = [
                'name'   => 'Connect',
                'status' => 'error',
                'detail' => $e->getMessage()
            ];
            if ($client) {
                $steps[] = [
                    'name'   => 'Last Request',
                    'status' => 'error',
                    'detail' => $client->getLastRequest() ?? 'n/a'
                ];
                $steps[] = [
                    'name'   => 'Last Response',
                    'status' => 'error',
                    'detail' => $client->getLastResponse() ?? 'n/a'
                ];
            }
            $logger->log('error', 'vcenter_test', $e->getMessage());
        }

        $result = [
            'vcenter' => $vcenter->name,
            'steps'   => $steps,
            'hosts'   => $hosts
        ];

        $this->view->title = $this->translate('vCenter Test Results');
        $this->addContent(new TestResults($result));
        $this->addContent(
            Html::tag('div', ['class' => 'proactiveha-pagination'], [
                new Link($this->translate('Back'), Url::fromPath('proactiveha/config'))
            ])
        );
    }

    public function databaseAction()
    {
        $form = new DatabaseConfigForm();
        $form->populate([
            'database_resource' => Config::module('proactiveha')->get('database', 'resource')
        ]);
        $form->handleRequest($this->getServerRequest());

        $this->view->tabs = $this->Module()->getConfigTabs()->activate('database');
        $this->view->title = $this->translate('Database Configuration');
        $this->addContent($form);
    }
}
