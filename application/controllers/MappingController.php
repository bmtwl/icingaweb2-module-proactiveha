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
use Icinga\Module\Proactiveha\Model\Cluster;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\ClusterSafety;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use Icinga\Module\Proactiveha\Util\ProviderId;
use Icinga\Module\Proactiveha\Web\Widget\MappingTable;
use Icinga\Web\Notification;
use Icinga\Web\Session;
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
            ->with(['vcenter', 'state', 'cluster'])
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

    public function pushAction()
    {
        $this->assertPost();
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;

        $mapping = $this->loadMapping($id);
        $logger = new EventLogger($this->getDb());
        $logger->setContext($mapping->id, $mapping->vcenter_id);

        try {
            $state = State::on($this->getDb())
                ->filter(Filter::equal('mapping_id', $mapping->id))
                ->first();

            if (!$state) {
                throw new \RuntimeException('No state found for mapping');
            }

            if ($state->vsphere_state === 'red') {
                $safety = new ClusterSafety($this->getDb(), $logger);
                $check = $safety->canPushRed($mapping);
                if (!$check['allowed']) {
                    $this->getDb()->update('proactiveha_state', [
                        'push_status' => 'blocked',
                        'last_error'  => $check['reason'],
                        'updated_at'  => date('Y-m-d H:i:s')
                    ], ['id = ?' => $state->id]);
                    Notification::warning($check['reason']);
                    $this->redirectNow(Url::fromPath('proactiveha/mapping'));
                }
            }

            $client = $this->createClient($mapping->vcenter);

            $providerId = $mapping->vcenter->provider_key;
            if (empty($providerId)) {
                $providerId = $client->registerProvider();
                $this->getDb()->update('proactiveha_vcenter', [
                    'provider_key'        => $providerId,
                    'provider_registered' => 1,
                    'updated_at'          => date('Y-m-d H:i:s')
                ], ['id = ?' => $mapping->vcenter_id]);
            }

            if (!$client->hasMonitoredEntity($providerId, $mapping->vsphere_host_moid)) {
                $client->addMonitoredEntities($providerId, [$mapping->vsphere_host_moid]);
            }

            $client->postHealthUpdates($providerId, $mapping->vsphere_host_moid, 'Power', $state->vsphere_state);

            $this->getDb()->update('proactiveha_state', [
                'push_status'   => 'synced',
                'last_pushed'   => date('Y-m-d H:i:s'),
                'push_attempts' => 0,
                'retry_at'      => null,
                'last_error'    => null,
                'updated_at'    => date('Y-m-d H:i:s')
            ], ['id = ?' => $state->id]);

            $logger->log('info', 'manual_push', "Pushed {$state->vsphere_state} for {$mapping->vsphere_host_name}");
            Notification::success($this->translate('State pushed successfully'));
        } catch (\Exception $e) {
            $this->getDb()->update('proactiveha_state', [
                'push_status' => 'failed',
                'last_error'  => $e->getMessage(),
                'updated_at'  => date('Y-m-d H:i:s')
            ], ['mapping_id = ?' => $mapping->id]);
            $logger->log('error', 'manual_push_failed', $e->getMessage());
            Notification::error($e->getMessage());
        }

        $this->redirectNow(Url::fromPath('proactiveha/mapping'));
    }

    public function forceAction()
    {
        $this->assertPost();
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        $forcedState = $this->getServerRequest()->getParsedBody()['state'] ?? null;

        $validStates = ['green' => 0, 'yellow' => 1, 'red' => 2];
        if (!array_key_exists($forcedState, $validStates)) {
            Notification::error($this->translate('Invalid state'));
            $this->redirectNow(Url::fromPath('proactiveha/mapping'));
        }

        $mapping = $this->loadMapping($id);
        $logger = new EventLogger($this->getDb());
        $logger->setContext($mapping->id, $mapping->vcenter_id);

        $stateName = $this->stateName($validStates[$forcedState]);

        try {
            if ($forcedState === 'red') {
                $safety = new ClusterSafety($this->getDb(), $logger);
                $check = $safety->canPushRed($mapping);
                if (!$check['allowed']) {
                    $this->getDb()->update('proactiveha_state', [
                        'push_status' => 'blocked',
                        'last_error'  => $check['reason'],
                        'updated_at'  => date('Y-m-d H:i:s')
                    ], ['mapping_id = ?' => $mapping->id]);
                    $logger->log('warning', 'manual_force_state_blocked', $check['reason']);
                    Notification::warning($check['reason']);
                    $this->redirectNow(Url::fromPath('proactiveha/mapping'));
                }
            }

            $existing = State::on($this->getDb())
                ->filter(Filter::equal('mapping_id', $mapping->id))
                ->first();

            $now = date('Y-m-d H:i:s');
            $data = [
                'desired_state'      => $validStates[$forcedState],
                'desired_state_name' => $stateName,
                'vsphere_state'      => $forcedState,
                'push_status'        => 'pending',
                'push_attempts'      => 0,
                'retry_at'           => null,
                'last_error'         => null,
                'last_evaluated'     => $now,
                'updated_at'         => $now
            ];

            if ($existing) {
                $this->getDb()->update('proactiveha_state', $data, ['mapping_id = ?' => $mapping->id]);
            } else {
                $data['mapping_id'] = $mapping->id;
                $this->getDb()->insert('proactiveha_state', $data);
            }

            $logger->log('info', 'manual_force_state', "Forced state to $forcedState for {$mapping->vsphere_host_name}");
            Notification::success(sprintf($this->translate('State forced to %s'), $forcedState));
        } catch (\Exception $e) {
            $logger->log('error', 'manual_force_state_failed', $e->getMessage());
            Notification::error($e->getMessage());
        }

        $this->redirectNow(Url::fromPath('proactiveha/mapping'));
    }

    public function resolveAction()
    {
        $this->assertPost();
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;

        $mapping = $this->loadMapping($id);
        $logger = new EventLogger($this->getDb());
        $logger->setContext($mapping->id, $mapping->vcenter_id);

        try {
            $client = $this->createClient($mapping->vcenter);
            $moid = $client->findHostMoid($mapping->vsphere_host_name);
            $clusterId = $this->resolveClusterId($mapping->vcenter_id, $moid);

            $this->getDb()->update('proactiveha_mapping', [
                'vsphere_host_moid'  => $moid,
                'cluster_id'         => $clusterId,
                'uuid_last_resolved' => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s')
            ], ['id = ?' => $mapping->id]);

            $this->getDb()->update('proactiveha_state', [
                'push_status' => 'pending',
                'updated_at'  => date('Y-m-d H:i:s')
            ], ['mapping_id = ?' => $mapping->id]);

            $logger->log('info', 'manual_resolve_moid', "Resolved MOID $moid for {$mapping->vsphere_host_name}");
            Notification::success(sprintf($this->translate('Host MOID resolved: %s'), $moid));
        } catch (\Exception $e) {
            $logger->log('error', 'manual_resolve_moid_failed', $e->getMessage());
            Notification::error($e->getMessage());
        }

        $this->redirectNow(Url::fromPath('proactiveha/mapping'));
    }

    public function logsAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        $mapping = $this->loadMapping($id);

        $this->redirectNow(Url::fromPath('proactiveha/log', ['mapping_id' => $mapping->id]));
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

    private function loadMapping($id)
    {
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

        return $mapping;
    }

    private function createClient($vcenter)
    {
        $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
        return new VcenterClient([
            'url'        => $vcenter->url,
            'username'   => $vcenter->username,
            'password'   => $password,
            'verify_ssl' => (bool) $vcenter->verify_ssl
        ]);
    }

    private function resolveClusterId($vcenterId, $moid)
    {
        $vcenter = Vcenter::on($this->getDb())
            ->filter(Filter::equal('id', $vcenterId))
            ->first();

        if (!$vcenter) {
            return null;
        }

        $clusters = iterator_to_array(
            Cluster::on($this->getDb())
                ->filter(Filter::equal('vcenter_id', $vcenterId))
                ->execute()
        );

        if (count($clusters) === 0) {
            return null;
        }

        $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
        $client = new VcenterClient([
            'url'        => $vcenter->url,
            'username'   => $vcenter->username,
            'password'   => $password,
            'verify_ssl' => (bool) $vcenter->verify_ssl
        ]);

        try {
            $client->connect();

            foreach ($clusters as $cluster) {
                $hosts = $client->listClusterHosts($cluster->mo_id);
                foreach ($hosts as $host) {
                    if ($host['moid'] === $moid) {
                        return (int) $cluster->id;
                    }
                }
            }
        } catch (\Exception $e) {
            // Best-effort cluster resolution
        }

        return null;
    }

    private function assertPost()
    {
        if ($this->getServerRequest()->getMethod() !== 'POST') {
            throw new \Icinga\Exception\Http\HttpException(405, $this->translate('This action must be triggered via POST'));
        }

        $token = $this->getServerRequest()->getParsedBody()['csrf_token'] ?? '';
        if ($token !== Session::getSession()->getId()) {
            throw new \Icinga\Exception\Http\HttpException(403, $this->translate('Invalid CSRF token'));
        }
    }

    private function stateName($state)
    {
        switch ($state) {
            case 0: return 'OK';
            case 1: return 'WARNING';
            case 2: return 'CRITICAL';
            default: return 'UNKNOWN';
        }
    }
}
