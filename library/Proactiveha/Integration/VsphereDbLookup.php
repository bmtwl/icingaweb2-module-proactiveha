<?php

namespace Icinga\Module\Proactiveha\Integration;

use Icinga\Application\Config;
use Icinga\Data\ResourceFactory;
use ipl\Sql\Connection;
use ipl\Sql\Config as SqlConfig;
use ipl\Sql\Expression;
use ipl\Sql\Select;

class VsphereDbLookup
{
    private $db;

    public function __construct()
    {
        $config = Config::module('vspheredb');
        $resourceName = $config->get('db', 'resource');
        if (!$resourceName) {
            $resourceName = $config->get('database', 'resource');
        }
        if (!$resourceName) {
            throw new \RuntimeException('vSphereDB resource not configured');
        }
        $this->db = new Connection(new SqlConfig(ResourceFactory::getResourceConfig($resourceName)));
    }

    public function findHostByName($hostName, $vcenterName = null)
    {
        $select = (new Select())
            ->from('host_system')
            ->columns([
                'uuid' => new Expression('host_system.uuid'),
                'moid' => new Expression('COALESCE(host_system.moid, host_system.moref)'),
                'host_name' => new Expression('COALESCE(host_system.host_name, host_system.name)'),
                'vcenter_name' => new Expression('vcenter.name')
            ])
            ->join('vcenter', 'vcenter.id = host_system.vcenter_id')
            ->where(['COALESCE(host_system.host_name, host_system.name) = ?' => $hostName]);

        if ($vcenterName) {
            $select->where(['vcenter.name = ?' => $vcenterName]);
        }

        return $this->db->fetchRow($select);
    }

    public function listHosts($vcenterName = null)
    {
        $select = (new Select())
            ->from('host_system')
            ->columns([
                'host_name' => new Expression('COALESCE(host_system.host_name, host_system.name)'),
                'vcenter_name' => new Expression('vcenter.name')
            ])
            ->join('vcenter', 'vcenter.id = host_system.vcenter_id')
            ->where(['host_system.connection_state = ?' => 'connected'])
            ->orderBy('vcenter.name')
            ->orderBy('host_system.name');

        if ($vcenterName) {
            $select->where(['vcenter.name = ?' => $vcenterName]);
        }

        return $this->db->fetchAll($select);
    }
}
