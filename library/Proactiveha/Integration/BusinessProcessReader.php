<?php

namespace Icinga\Module\Proactiveha\Integration;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Data\ConfigObject;
use Icinga\Module\Businessprocess\Storage\LegacyStorage;

class BusinessProcessReader
{
    private $storage;

    public function __construct()
    {
        $moduleConfig = Config::module('businessprocess');
        $configArray = $moduleConfig->toArray();

        if (empty($configArray['processes']['path'])) {
            $configArray['processes']['path'] = '/etc/icingaweb2/modules/businessprocess/processes';
        }

        $this->storage = new LegacyStorage(new ConfigObject($configArray));
    }

    public function listConfigs()
    {
        $configs = $this->storage->listProcesses();

        if (!is_array($configs)) {
            return [];
        }

        $result = [];
        foreach ($configs as $key => $value) {
            if (is_object($value) && method_exists($value, 'getName')) {
                $name = $value->getName();
                $result[$name] = $name;
            } elseif (is_string($value) && !is_int($key)) {
                $result[$key] = $value;
            } elseif (is_string($value)) {
                $result[$value] = $value;
            } elseif (is_string($key)) {
                $result[$key] = $key;
            }
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    public function getNodes($bpName)
    {
        $bpConfig = $this->loadConfig($bpName);
        $nodes = [];

        foreach ($bpConfig->getNodes() as $node) {
            $name = $node->getName();
            $nodes[$name] = $name;
        }

        ksort($nodes, SORT_NATURAL | SORT_FLAG_CASE);
        return $nodes;
    }

    public function getNodeState($bpName, $nodeName)
    {
        $bpConfig = $this->loadConfig($bpName);
        $this->loadStates($bpConfig);

        $node = $bpConfig->getNode($nodeName);
        if (!$node) {
            return null;
        }

        $state = $node->getState();

        return [
            'state' => $state,
            'state_name' => $node->getStateName() ?: $this->stateName($state)
        ];
    }

    private function loadConfig($bpName)
    {
        return $this->storage->loadProcess($bpName);
    }

    private function loadStates($bpConfig)
    {
        try {
            if (\Icinga\Application\Modules\Module::exists('icingadb')
                && \Icinga\Module\Businessprocess\ProvidedHook\Icingadb\IcingadbSupport::useIcingaDbAsBackend()
            ) {
                \Icinga\Module\Businessprocess\State\IcingaDbState::apply($bpConfig);
            } else {
                \Icinga\Module\Businessprocess\State\MonitoringState::apply($bpConfig);
            }
        } catch (\Exception $e) {
            Logger::warning('BusinessProcessReader: Could not load BP states automatically: %s', $e->getMessage());
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
