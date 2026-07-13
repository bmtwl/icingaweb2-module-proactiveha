<?php

namespace Icinga\Module\Proactiveha\Controllers;

use Icinga\Application\Logger;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Common\RestrictionFilter;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Forms\ConfirmDeleteForm;
use Icinga\Module\Proactiveha\Forms\MappingForm;
use Icinga\Module\Proactiveha\Integration\BusinessProcessReader;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use Icinga\Module\Proactiveha\Util\ProviderId;
use Icinga\Module\Proactiveha\Web\Widget\MappingTable;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class MappingController extends CompatController
{
    use Database;
    use RestrictionFilter;

    public function init()
    {
        $this->assertPermission('proactiveha/admin');
    }

    public function indexAction()
    {
        $query = Mapping::on($this->getDb())
            ->with(['vcenter', 'state'])
            ->orderBy('bp_config_name');

        $this->applyRestrictions($query, 'vcenter');

        $this->view->title = $this->translate('Mappings');
        $this->addContent(new MappingTable($query->execute()));
    }

    public function addAction()
    {
        $form = $this->prepareForm();
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/mapping');
        }

        $this->view->title = $this->translate('Add Mapping');
        $this->addContent($form);
    }

    public function editAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        if ($id === null) {
            throw new NotFoundError($this->translate('Mapping not found'));
        }

        $mapping = Mapping::on($this->getDb())
            ->with('vcenter')
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$mapping) {
            throw new NotFoundError($this->translate('Mapping not found'));
        }

        $form = $this->prepareForm($id);
        $form->populate([
            'vcenter_id'        => $mapping->vcenter_id,
            'bp_node'           => $mapping->bp_config_name . '|' . $mapping->bp_node_name,
            'vsphere_host_name' => $mapping->vsphere_host_name,
            'vsphere_host_moid' => $mapping->vsphere_host_moid,
            'enabled'           => (string) $mapping->enabled
        ]);
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/mapping');
        }

        $this->view->title = $this->translate('Edit Mapping');
        $this->addContent($form);
    }

    public function deleteAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        if ($id === null) {
            throw new NotFoundError($this->translate('Mapping not found'));
        }

        $form = new ConfirmDeleteForm(
            $this->getDb(),
            'proactiveha_mapping',
            Url::fromPath('proactiveha/mapping')->getAbsoluteUrl(),
            $id
        );
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/mapping');
        }

        $this->view->title = $this->translate('Delete Mapping');
        $this->addContent($form);
    }

    public function testAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        $mode = $this->getServerRequest()->getQueryParams()['mode'] ?? 'moid';

        $mapping = Mapping::on($this->getDb())
            ->with('vcenter')
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$mapping) {
            throw new NotFoundError($this->translate('Mapping not found'));
        }

        $logger = new EventLogger($this->getDb());
        $logger->setContext($mapping->id, $mapping->vcenter_id);

        $result = [
            'success' => false,
            'message' => '',
            'details' => []
        ];

        try {
            $password = PasswordEncryptor::decrypt($mapping->vcenter->password, ModuleConfig::keyFile());
            $client = new VcenterClient([
                'url'        => $mapping->vcenter->url,
                'username'   => $mapping->vcenter->username,
                'password'   => $password,
                'verify_ssl' => (bool) $mapping->vcenter->verify_ssl
            ]);
            $client->connect();

            if ($mode === 'push') {
                if (empty($mapping->vsphere_host_moid)) {
                    throw new \RuntimeException('Mapping has no vsphere_host_moid');
                }

                $providerId = $mapping->vcenter->provider_key;
                if (empty($providerId)) {
                    $providerId = $client->registerProvider();
                    $this->getDb()->update('proactiveha_vcenter', [
                        'provider_key'        => $providerId,
                        'provider_registered' => 1,
                        'updated_at'          => date('Y-m-d H:i:s')
                    ], ['id = ?' => $mapping->vcenter_id]);
                }

                $result['details'][] = "Provider ID: " . ProviderId::toUuid($providerId);

                $client->postHealthUpdates($providerId, $mapping->vsphere_host_moid, 'Power', 'red');
                $result['details'][] = "Pushed red state for {$mapping->vsphere_host_name}";

                $logger->log('info', 'mapping_test_push', "Pushed red state for {$mapping->vsphere_host_name}");
            } else {
                $moid = $client->findHostMoid($mapping->vsphere_host_name);
                $result['details'][] = "Resolved MOID: $moid";

                $logger->log('info', 'mapping_test_moid', "Resolved MOID $moid for {$mapping->vsphere_host_name}");
            }

            $result['success'] = true;
        } catch (\Exception $e) {
            $result['message'] = $e->getMessage();
            $result['details'][] = "Last request: " . ($client->getLastRequest() ?? 'n/a');
            $result['details'][] = "Last response: " . ($client->getLastResponse() ?? 'n/a');
            $logger->log('error', 'mapping_test', $e->getMessage());
        }

        $this->view->title = $this->translate('Mapping Test Results');
        $this->addContent(Html::tag('div', ['class' => 'content'], [
            Html::tag('h2', $result['success'] ? $this->translate('Test Successful') : $this->translate('Test Failed')),
            Html::tag('p', $result['message']),
            Html::tag('pre', implode("\n", $result['details'])),
            Html::tag('a', ['href' => Url::fromPath('proactiveha/mapping')->getAbsoluteUrl()], $this->translate('Back'))
        ]));
    }

    private function prepareForm($id = null)
    {
        $db = $this->getDb();

        $vcenterQuery = Vcenter::on($db)
            ->filter(Filter::equal('enabled', 1))
            ->orderBy('name');
        $this->applyRestrictions($vcenterQuery);

        $vcenterOptions = ['' => $this->translate('Please choose')];
        foreach ($vcenterQuery->execute() as $vcenter) {
            $vcenterOptions[$vcenter->id] = $vcenter->name;
        }

        $bpNodeOptions = [];
        try {
            $bpReader = new BusinessProcessReader();
            $configs = $bpReader->listConfigs();
            foreach ($configs as $configName => $configLabel) {
                $nodes = $bpReader->getNodes($configName);
                if (!empty($nodes)) {
                    $bpNodeOptions[$configName] = $nodes;
                }
            }
        } catch (\Exception $e) {
            Logger::error('Failed to load Business Process configs: %s', $e->getMessage());
        }

        return (new MappingForm($db, $id))
            ->setVcenterOptions($vcenterOptions)
            ->setBpNodeOptions($bpNodeOptions);
    }
}
