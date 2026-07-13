<?php

namespace Icinga\Module\Proactiveha\Common;

use Icinga\Application\Config as AppConfig;
use Icinga\Data\ResourceFactory;
use ipl\Sql\Connection;
use ipl\Sql\Config as SqlConfig;

trait Database
{
    private $db;

    public function getDb()
    {
        if ($this->db === null) {
            $resourceName = AppConfig::module('proactiveha')->get('database', 'resource');
            if (!$resourceName) {
                throw new \RuntimeException('Database resource not configured');
            }
            $this->db = new Connection(new SqlConfig(ResourceFactory::getResourceConfig($resourceName)));
        }

        return $this->db;
    }
}
