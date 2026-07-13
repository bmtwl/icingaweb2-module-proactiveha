<?php

namespace Icinga\Module\Proactiveha\Util;

use Icinga\Application\Logger;
use ipl\Sql\Connection;

class EventLogger
{
    private $db;
    private $mappingId;
    private $vcenterId;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function setContext($mappingId = null, $vcenterId = null)
    {
        $this->mappingId = $mappingId;
        $this->vcenterId = $vcenterId;
    }

    public function log($level, $eventType, $message, $context = [])
    {
        switch ($level) {
            case 'error':
                Logger::error("[$eventType] $message");
                break;
            case 'warning':
                Logger::warning("[$eventType] $message");
                break;
            case 'info':
                Logger::info("[$eventType] $message");
                break;
            case 'debug':
                Logger::debug("[$eventType] $message");
                break;
        }

        if ($level === 'debug') {
            return;
        }

        try {
            $this->db->insert('proactiveha_log', [
                'mapping_id' => $this->mappingId,
                'vcenter_id' => $this->vcenterId,
                'level' => $level,
                'event_type' => $eventType,
                'message' => $message,
                'context' => empty($context) ? null : json_encode($context)
            ]);
        } catch (\Exception $e) {
            Logger::error("Failed to persist log entry: " . $e->getMessage());
        }
    }
}
