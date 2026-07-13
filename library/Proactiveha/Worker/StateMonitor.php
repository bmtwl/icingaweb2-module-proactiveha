<?php

namespace Icinga\Module\Proactiveha\Worker;

use ipl\Sql\Connection;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Integration\BusinessProcessReader;
use Icinga\Module\Proactiveha\Util\Config;
use Icinga\Module\Proactiveha\Util\EventLogger;
use ipl\Stdlib\Filter;

class StateMonitor
{
    private $db;
    private $logger;
    private $bpReader;
    private $running = true;
    private $once = false;

    public function __construct(Connection $db, $once = false)
    {
        $this->db = $db;
        $this->logger = new EventLogger($db);
        $this->bpReader = new BusinessProcessReader();
        $this->once = (bool) $once;
    }

    public function run()
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }

        $this->logger->log('info', 'monitor_start', 'State monitor started');

        do {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                $this->cycle();
            } catch (\Exception $e) {
                $this->logger->log('error', 'monitor_cycle_exception', $e->getMessage());
            }

            if (!$this->once) {
                sleep(Config::monitorInterval());
            }
        } while (!$this->once && $this->running);

        $this->logger->log('info', 'monitor_stop', 'State monitor stopped');
    }

    public function shutdown()
    {
        $this->running = false;
    }

    private function cycle()
    {
        $mappings = Mapping::on($this->db)
            ->with('vcenter')
            ->filter(Filter::equal('enabled', 1))
            ->execute();

        foreach ($mappings as $mapping) {
            if (!$mapping->vcenter->enabled) {
                continue;
            }

            $this->logger->setContext($mapping->id, $mapping->vcenter_id);
            $this->logger->log('debug', 'evaluate_mapping', "Evaluating {$mapping->bp_config_name}/{$mapping->bp_node_name}");

            $state = $this->bpReader->getNodeState($mapping->bp_config_name, $mapping->bp_node_name);
            if ($state === null) {
                $this->logger->log('warning', 'bp_node_not_found', "Node {$mapping->bp_node_name} not found");
                continue;
            }

            $vsphereState = StateTranslator::toVsphereState($state['state']);
            if ($vsphereState === null) {
                $this->logger->log('warning', 'state_unmapped', "State {$state['state_name']} cannot be mapped to vSphere");
                continue;
            }

            $this->updateDesiredState($mapping, $state['state'], $state['state_name'], $vsphereState);
        }

        $this->logger->setContext();
    }

    private function updateDesiredState($mapping, $state, $stateName, $vsphereState)
    {
        $current = State::on($this->db)
            ->filter(Filter::equal('mapping_id', $mapping->id))
            ->first();

        $now = date('Y-m-d H:i:s');

        if ($current && $current->desired_state == $state) {
            $this->db->update('proactiveha_state', [
                'last_evaluated' => $now
            ], ['mapping_id = ?' => $mapping->id]);
            return;
        }

        $data = [
            'desired_state' => $state,
            'desired_state_name' => $stateName,
            'vsphere_state' => $vsphereState,
            'push_status' => 'pending',
            'push_attempts' => 0,
            'retry_at' => null,
            'last_error' => null,
            'last_evaluated' => $now,
            'updated_at' => $now
        ];

        if ($current) {
            $this->db->update('proactiveha_state', $data, ['mapping_id = ?' => $mapping->id]);
            $this->logger->log('info', 'state_changed', "State changed to $stateName");
        } else {
            $data['mapping_id'] = $mapping->id;
            $this->db->insert('proactiveha_state', $data);
            $this->logger->log('info', 'state_created', "Initial state set to $stateName");
        }
    }
}
