<?php

namespace Icinga\Module\Proactiveha\Controllers;

use Icinga\Exception\NotFoundError;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Common\RestrictionFilter;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Forms\ClusterForm;
use Icinga\Module\Proactiveha\Forms\ConfirmDeleteForm;
use Icinga\Module\Proactiveha\Model\Cluster;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Util\EventLogger;
use Icinga\Module\Proactiveha\Util\ProviderId;
use Icinga\Module\Proactiveha\Web\Widget\ClusterTable;
use Icinga\Web\Notification;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class ClusterController extends CompatController
{
    use Database;
    use RestrictionFilter;

    public function init()
    {
        $this->assertPermission('proactiveha/admin');
    }

    public function indexAction()
    {
        $query = Cluster::on($this->getDb())
            ->with('vcenter')
            ->orderBy('name');

        $this->applyRestrictions($query, 'vcenter');

        $this->view->title = $this->translate('Cluster Configurations');
        $this->addContent(new ClusterTable($query->execute()));
    }

    public function addAction()
    {
        $form = $this->prepareForm();
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/cluster');
        }

        $this->view->title = $this->translate('Add Cluster Configuration');
        $this->addContent($form);
    }

    public function editAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        if ($id === null) {
            throw new NotFoundError($this->translate('Cluster not found'));
        }

        $cluster = Cluster::on($this->getDb())
            ->with('vcenter')
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$cluster) {
            throw new NotFoundError($this->translate('Cluster not found'));
        }

        $form = $this->prepareForm($id);
        $form->populate([
            'vcenter_cluster'      => $cluster->vcenter_id . '|' . $cluster->mo_id,
            'cluster_mode'         => $cluster->cluster_mode,
            'moderate_remediation' => $cluster->moderate_remediation,
            'severe_remediation'   => $cluster->severe_remediation,
            'enabled'              => (bool) $cluster->enabled
        ]);
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/cluster');
        }

        $this->view->title = $this->translate('Edit Cluster Configuration');
        $this->addContent($form);
    }

    public function deleteAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        if ($id === null) {
            throw new NotFoundError($this->translate('Cluster not found'));
        }

        $form = new ConfirmDeleteForm(
            $this->getDb(),
            'proactiveha_cluster',
            Url::fromPath('proactiveha/cluster')->getAbsoluteUrl(),
            $id
        );
        $form->handleRequest($this->getServerRequest());
        if ($form->wasSaved()) {
            $this->redirectNow('proactiveha/cluster');
        }

        $this->view->title = $this->translate('Delete Cluster Configuration');
        $this->addContent($form);
    }

    public function registerAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        $this->toggleProvider($id, true);
    }

    public function unregisterAction()
    {
        $id = $this->getServerRequest()->getQueryParams()['id'] ?? null;
        $this->toggleProvider($id, false);
    }

    private function toggleProvider($id, $register)
    {
        if ($id === null) {
            throw new NotFoundError($this->translate('Cluster not found'));
        }

        $cluster = Cluster::on($this->getDb())
            ->with('vcenter')
            ->filter(Filter::equal('id', $id))
            ->first();

        if (!$cluster) {
            throw new NotFoundError($this->translate('Cluster not found'));
        }

        $logger = new EventLogger($this->getDb());
        $logger->setContext(null, $cluster->vcenter_id);

        try {
            $client = $this->createClient($cluster->vcenter);

            if (!$client->isHealthUpdateManagerAvailable()) {
                throw new \RuntimeException('HealthUpdateManager not available');
            }

            $providerId = $cluster->vcenter->provider_key;
            if (empty($providerId)) {
                $providerId = $client->registerProvider();
                $this->getDb()->update('proactiveha_vcenter', [
                    'provider_key'        => $providerId,
                    'provider_registered' => 1,
                    'updated_at'          => date('Y-m-d H:i:s')
                ], ['id = ?' => $cluster->vcenter_id]);
                $logger->log('info', 'cluster_register_provider', 'Registered provider: ' . ProviderId::toUuid($providerId));
            }

            if ($register) {
                $hosts = $client->listClusterHosts($cluster->mo_id);
                $hostMoIds = array_filter(array_column($hosts, 'moid'));
                if (!empty($hostMoIds)) {
                    $client->addMonitoredEntities($providerId, $hostMoIds);
                    $logger->log('info', 'cluster_add_monitored_entities', sprintf(
                        'Added %d host(s) as monitored entities for cluster %s',
                        count($hostMoIds),
                        $cluster->name
                    ));
                }

                $this->getDb()->update('proactiveha_cluster', [
                    'provider_enabled' => 1,
                    'last_enabled_at'  => date('Y-m-d H:i:s'),
                    'last_error'       => null,
                    'updated_at'       => date('Y-m-d H:i:s')
                ], ['id = ?' => $cluster->id]);
                $logger->log('info', 'cluster_register_provider', "Registered provider for cluster {$cluster->name}");
                Notification::success($this->translate('Provider registered for cluster'));
            } else {
                $client->unregisterProvider($providerId);
                $this->getDb()->update('proactiveha_cluster', [
                    'provider_enabled'  => 0,
                    'last_disabled_at'  => date('Y-m-d H:i:s'),
                    'last_error'        => null,
                    'updated_at'        => date('Y-m-d H:i:s')
                ], ['id = ?' => $cluster->id]);
                $logger->log('info', 'cluster_unregister_provider', "Unregistered provider for cluster {$cluster->name}");
                Notification::success($this->translate('Provider unregistered for cluster'));
            }
        } catch (\Exception $e) {
            $this->getDb()->update('proactiveha_cluster', [
                'last_error' => $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id = ?' => $cluster->id]);
            $logger->log('error', 'cluster_toggle', $e->getMessage());
            Notification::error($e->getMessage());
        }

        $this->redirectNow('proactiveha/cluster');
    }

    private function prepareForm($id = null)
    {
        $db = $this->getDb();

        $clusterOptions = ['' => $this->translate('Please choose')];

        $vcenterQuery = Vcenter::on($db)
            ->filter(Filter::equal('enabled', 1))
            ->orderBy('name');
        $this->applyRestrictions($vcenterQuery);

        foreach ($vcenterQuery->execute() as $vcenter) {
            try {
                $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
                $client = new VcenterClient([
                    'url'        => $vcenter->url,
                    'username'   => $vcenter->username,
                    'password'   => $password,
                    'verify_ssl' => (bool) $vcenter->verify_ssl
                ]);
                $client->connect();

                foreach ($client->listClusters() as $cluster) {
                    $key = $vcenter->id . '|' . $cluster['moid'];
                    $clusterOptions[$key] = $vcenter->name . ' / ' . $cluster['name'];
                }
            } catch (\Exception $e) {
                Notification::warning(sprintf(
                    $this->translate('Failed to load clusters from %s: %s'),
                    $vcenter->name,
                    $e->getMessage()
                ));
            }
        }

        if ($id !== null) {
            $cluster = Cluster::on($db)
                ->with('vcenter')
                ->filter(Filter::equal('id', $id))
                ->first();

            if ($cluster) {
                $key = $cluster->vcenter_id . '|' . $cluster->mo_id;
                $clusterOptions[$key] = ($cluster->vcenter ? $cluster->vcenter->name : 'N/A') . ' / ' . $cluster->name;
            }
        }

        return (new ClusterForm($db, $id))->setClusterOptions($clusterOptions);
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
}
