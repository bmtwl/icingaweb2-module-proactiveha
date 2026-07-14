<?php

namespace Icinga\Module\Proactiveha\Util;

use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Module\Proactiveha\Client\VcenterClient;
use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Integration\BusinessProcessReader;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Model\Vcenter;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

class LiveStateSnapshot
{
    /** @var Connection */
    private $db;

    /** @var array */
    private $clients = [];

    /** @var array */
    private $vcenterCache = [];

    /** @var array */
    private $healthUpdatesCache = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function capture()
    {
        $bpReader = new BusinessProcessReader();
        $mappings = Mapping::on($this->db)
            ->with('vcenter')
            ->filter(Filter::equal('enabled', 1))
            ->execute();

        $rows = [];
        $errors = [];

        foreach ($mappings as $mapping) {
            if (!$mapping->vcenter || !$mapping->vcenter->enabled) {
                continue;
            }

            $row = [
                'mapping_id'      => $mapping->id,
                'vcenter_id'      => $mapping->vcenter_id,
                'vcenter_name'    => $mapping->vcenter->name,
                'bp_config_name'  => $mapping->bp_config_name,
                'bp_node_name'    => $mapping->bp_node_name,
                'host_name'       => $mapping->vsphere_host_name,
                'host_moid'       => $mapping->vsphere_host_moid,
                'bp_state'        => null,
                'bp_state_name'   => 'UNKNOWN',
                'desired_state'   => null,
                'desired_state_name' => 'UNKNOWN',
                'vsphere_api_state' => null,
                'monitored'       => false,
                'match_status'    => 'unknown',
                'errors'          => []
            ];

            try {
                $bpState = $bpReader->getNodeState($mapping->bp_config_name, $mapping->bp_node_name);
                if ($bpState !== null) {
                    $row['bp_state'] = $bpState['state'];
                    $row['bp_state_name'] = $bpState['state_name'];
                } else {
                    $row['errors'][] = 'BP node not found';
                }
            } catch (\Exception $e) {
                $row['errors'][] = 'BP read failed: ' . $e->getMessage();
            }

            $state = State::on($this->db)
                ->filter(Filter::equal('mapping_id', $mapping->id))
                ->first();

            if ($state) {
                $row['desired_state'] = $state->desired_state;
                $row['desired_state_name'] = $state->desired_state_name;
            }

            try {
                $client = $this->getClient($mapping->vcenter);
                $providerId = $mapping->vcenter->provider_key;

                if (empty($providerId)) {
                    $row['errors'][] = 'No provider registered for vCenter';
                } elseif (empty($mapping->vsphere_host_moid)) {
                    $row['errors'][] = 'Host MOID not configured';
                } else {
                    $row['monitored'] = $client->hasMonitoredEntity($providerId, $mapping->vsphere_host_moid);

                    $updates = $this->getHealthUpdates($client, $mapping->vcenter, $providerId);
                    foreach ($updates as $update) {
                        $moid = $this->extractMoid($update->entity);
                        if ($moid === $mapping->vsphere_host_moid) {
                            $row['vsphere_api_state'] = $update->status;
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                $row['errors'][] = 'vCenter API error: ' . $e->getMessage();
            }

            $row['match_status'] = $this->computeMatchStatus($row);
            $rows[] = $row;
        }

        foreach ($this->clients as $vcenterId => $client) {
            try {
                $client->disconnect();
            } catch (\Exception $e) {
                // ignore disconnect errors
            }
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'rows'         => $rows,
            'errors'       => $errors
        ];
    }

    private function getClient($vcenter)
    {
        if (!isset($this->clients[$vcenter->id])) {
            $password = PasswordEncryptor::decrypt($vcenter->password, ModuleConfig::keyFile());
            $client = new VcenterClient([
                'url'        => $vcenter->url,
                'username'   => $vcenter->username,
                'password'   => $password,
                'verify_ssl' => (bool) $vcenter->verify_ssl
            ]);
            $client->connect();
            $this->clients[$vcenter->id] = $client;
        }

        return $this->clients[$vcenter->id];
    }

    private function getHealthUpdates(VcenterClient $client, $vcenter, $providerId)
    {
        $cacheKey = $vcenter->id . ':' . $providerId;

        if (!isset($this->healthUpdatesCache[$cacheKey])) {
            $this->healthUpdatesCache[$cacheKey] = $client->queryHealthUpdates($providerId);
        }

        return $this->healthUpdatesCache[$cacheKey];
    }

    private function extractMoid($entity)
    {
        if ($entity instanceof \Icinga\Module\Proactiveha\Client\ManagedObjectReference) {
            return $entity->_;
        }
        if (is_object($entity)) {
            return $entity->_ ?? $entity->value ?? null;
        }
        if (is_array($entity)) {
            return $entity['_'] ?? $entity['value'] ?? null;
        }
        return $entity;
    }

    private function computeMatchStatus(array $row)
    {
        if (!empty($row['errors'])) {
            return 'error';
        }

        $bpStateName = strtolower($row['bp_state_name']);
        $desiredStateName = strtolower($row['desired_state_name']);
        $apiState = $row['vsphere_api_state'] !== null ? strtolower($row['vsphere_api_state']) : null;

        if ($bpStateName !== $desiredStateName && $row['desired_state'] !== null) {
            return 'stale';
        }

        if ($apiState === null) {
            return 'unknown';
        }

        $expectedApi = $this->stateToVsphere($row['bp_state']);
        if ($expectedApi === null) {
            return 'unknown';
        }

        if ($apiState === $expectedApi) {
            return 'ok';
        }

        return 'mismatch';
    }

    private function stateToVsphere($icingaState)
    {
        switch ($icingaState) {
            case 0: return 'green';
            case 1: return 'yellow';
            case 2: return 'red';
            default: return null;
        }
    }
}
