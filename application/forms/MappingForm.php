<?php

namespace Icinga\Module\Proactiveha\Forms;

use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Model\Cluster;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Web\Notification;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatForm;
use Psr\Http\Message\ServerRequestInterface;

class MappingForm extends CompatForm
{
    /** @var Connection */
    private $db;

    /** @var int|null */
    private $id;

    /** @var bool */
    private $saved = false;

    private $vcenterOptions = [];
    private $bpNodeOptions  = [];

    public function __construct(Connection $db, $id = null)
    {
        $this->db = $db;
        $this->id = $id !== null ? (int) $id : null;
    }

    public function wasSaved(): bool
    {
        return $this->saved;
    }

    public function handleRequest(ServerRequestInterface $request)
    {
        $this->ensureAssembled();
        return parent::handleRequest($request);
    }

    public function setVcenterOptions(array $options)
    {
        $this->vcenterOptions = $options;
        return $this;
    }

    public function setBpNodeOptions(array $options)
    {
        $this->bpNodeOptions = $this->flattenBpNodeOptions($options);
        return $this;
    }

    protected function assemble()
    {
        $this->addElement('select', 'vcenter_id', [
            'label'    => $this->translate('vCenter'),
            'required' => true,
            'options'  => $this->vcenterOptions
        ]);

        $this->addElement('select', 'bp_node', [
            'label'       => $this->translate('Business Process Node'),
            'description' => $this->translate('Config and node separated by a pipe'),
            'required'    => true,
            'options'     => $this->bpNodeOptions
        ]);

        $this->addElement('text', 'vsphere_host_name', [
            'label'       => $this->translate('vSphere Host Name'),
            'description' => $this->translate('Host name as shown in vSphere (e.g. esxi01.example.com)'),
            'required'    => true
        ]);

        $this->addElement('text', 'vsphere_host_moid', [
            'label'       => $this->translate('vSphere Host MOID'),
            'description' => $this->translate('Managed Object ID (e.g. host-123). Leave empty to auto-resolve via vCenter.'),
            'required'    => false
        ]);

        $this->addElement('select', 'enabled', [
            'label'    => $this->translate('Enabled'),
            'required' => true,
            'options'  => [
                '1' => $this->translate('Yes'),
                '0' => $this->translate('No')
            ]
        ]);

        $this->addElement('submit', 'submit', [
            'label' => $this->translate('Save')
        ]);
    }

    protected function onSuccess()
    {
        try {
            $values = $this->getValues();

            list($bpConfigName, $bpNodeName) = array_pad(explode('|', $values['bp_node'], 2), 2, '');

            if (empty($bpConfigName) || empty($bpNodeName)) {
                throw new \RuntimeException($this->translate('A valid Business Process node must be selected'));
            }

            $moid = !empty($values['vsphere_host_moid']) ? $values['vsphere_host_moid'] : null;
            if ($moid !== null && !preg_match('/^host-\d+$/i', $moid)) {
                throw new \RuntimeException($this->translate('vSphere Host MOID must match host-<number>'));
            }
            if ($moid === null) {
                $moid = $this->resolveMoid((int) $values['vcenter_id'], $values['vsphere_host_name']);
            }

            $clusterId = $this->resolveClusterId((int) $values['vcenter_id'], $moid);

            $data = [
                'vcenter_id'         => (int) $values['vcenter_id'],
                'cluster_id'         => $clusterId,
                'bp_config_name'     => $bpConfigName,
                'bp_node_name'       => $bpNodeName,
                'vsphere_host_name'  => $values['vsphere_host_name'],
                'vsphere_host_moid'  => $moid,
                'uuid_last_resolved' => date('Y-m-d H:i:s'),
                'enabled'            => (int) $values['enabled'],
                'updated_at'         => date('Y-m-d H:i:s')
            ];

            if ($this->id === null) {
                $data['created_at'] = date('Y-m-d H:i:s');
                $this->db->insert('proactiveha_mapping', $data);

                $mapping = Mapping::on($this->db)
                    ->filter(Filter::all(
                        Filter::equal('vcenter_id', $data['vcenter_id']),
                        Filter::equal('vsphere_host_name', $data['vsphere_host_name'])
                    ))
                    ->first();

                if (!$mapping) {
                    throw new \RuntimeException('Failed to retrieve newly created mapping');
                }

                $mappingId = $mapping->id;
                $message   = $this->translate('Mapping created');
            } else {
                $this->db->update('proactiveha_mapping', $data, ['id = ?' => $this->id]);
                $mappingId = $this->id;
                $message   = $this->translate('Mapping updated');
            }

            $existing = State::on($this->db)
                ->filter(Filter::equal('mapping_id', $mappingId))
                ->first();

            if (!$existing) {
                $this->db->insert('proactiveha_state', [
                    'mapping_id'         => $mappingId,
                    'desired_state'      => 0,
                    'desired_state_name' => 'OK',
                    'vsphere_state'      => 'green',
                    'push_status'        => 'pending'
                ]);
            } else {
                $this->db->update('proactiveha_state', [
                    'push_status' => 'pending',
                    'updated_at'  => date('Y-m-d H:i:s')
                ], ['mapping_id = ?' => $mappingId]);
            }

            Notification::success($message);
            $this->saved = true;
        } catch (\Exception $e) {
            Notification::error($e->getMessage());
            $this->saved = false;
        }
    }

    private function resolveMoid($vcenterId, $hostName)
    {
        $vcenter = Vcenter::on($this->db)
            ->filter(Filter::equal('id', $vcenterId))
            ->first();

        if (!$vcenter) {
            throw new \RuntimeException('Selected vCenter not found');
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
            return $client->findHostMoid($hostName);
        } catch (\SoapFault $e) {
            throw new \RuntimeException('vCenter SOAP error: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to resolve host MOID: ' . $e->getMessage());
        }
    }

    private function resolveClusterId($vcenterId, $moid)
    {
        $vcenter = Vcenter::on($this->db)
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
            // Cluster resolution is best-effort; don't fail the whole save
        }

        return null;
    }

    private function flattenBpNodeOptions(array $options)
    {
        $flat = ['' => $this->translate('Please choose')];

        foreach ($options as $config => $nodes) {
            if (!is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $node) {
                $flat["$config|$node"] = "$config / $node";
            }
        }

        return $flat;
    }
}
