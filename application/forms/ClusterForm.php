<?php

namespace Icinga\Module\Proactiveha\Forms;

use Icinga\Web\Notification;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatForm;
use Psr\Http\Message\ServerRequestInterface;

class ClusterForm extends CompatForm
{
    /** @var Connection */
    private $db;

    /** @var int|null */
    private $id;

    /** @var bool */
    private $saved = false;

    private $clusterOptions = [];

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

    public function setClusterOptions(array $options)
    {
        $this->clusterOptions = $options;
        return $this;
    }

    protected function assemble()
    {
        $this->addElement('select', 'vcenter_cluster', [
            'label'       => $this->translate('vCenter / Cluster'),
            'description' => $this->translate('vSphere cluster to enable Proactive HA on'),
            'required'    => true,
            'options'     => $this->clusterOptions
        ]);

        $this->addElement('select', 'cluster_mode', [
            'label'    => $this->translate('Cluster Mode'),
            'required' => true,
            'options'  => [
                'Manual'    => $this->translate('Manual'),
                'Automated' => $this->translate('Automated')
            ]
        ]);

        $this->addElement('select', 'moderate_remediation', [
            'label'    => $this->translate('Moderate Remediation'),
            'required' => true,
            'options'  => [
                'QuarantineMode'  => $this->translate('Quarantine Mode'),
                'MaintenanceMode' => $this->translate('Maintenance Mode')
            ]
        ]);

        $this->addElement('select', 'severe_remediation', [
            'label'    => $this->translate('Severe Remediation'),
            'required' => true,
            'options'  => [
                'QuarantineMode'  => $this->translate('Quarantine Mode'),
                'MaintenanceMode' => $this->translate('Maintenance Mode')
            ]
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

            list($vcenterId, $moId) = array_pad(explode('|', $values['vcenter_cluster'], 2), 2, '');

            $label = $this->clusterOptions[$values['vcenter_cluster']] ?? $moId;
            $name = strpos($label, ' / ') !== false
                ? substr($label, strpos($label, ' / ') + 3)
                : $label;

            $data = [
                'vcenter_id'           => (int) $vcenterId,
                'mo_id'                => $moId,
                'name'                 => $name,
                'enabled'              => (int) $values['enabled'],
                'cluster_mode'         => $values['cluster_mode'],
                'moderate_remediation' => $values['moderate_remediation'],
                'severe_remediation'   => $values['severe_remediation'],
                'updated_at'           => date('Y-m-d H:i:s')
            ];

            if ($this->id === null) {
                $data['created_at'] = date('Y-m-d H:i:s');
                $this->db->insert('proactiveha_cluster', $data);
                $message = $this->translate('Cluster configuration created');
            } else {
                $this->db->update('proactiveha_cluster', $data, ['id = ?' => $this->id]);
                $message = $this->translate('Cluster configuration updated');
            }

            Notification::success($message);
            $this->saved = true;
        } catch (\Exception $e) {
            Notification::error($e->getMessage());
            $this->saved = false;
        }
    }
}
