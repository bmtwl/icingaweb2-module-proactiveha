<?php

namespace Icinga\Module\Proactiveha\Util;

use Icinga\Application\Config as AppConfig;

class Config
{
    public static function keyFile()
    {
        return AppConfig::module('proactiveha')->get('encryption', 'key_file', '/etc/icingaweb2/modules/proactiveha/key.pem');
    }

    public static function monitorInterval()
    {
        return (int) AppConfig::module('proactiveha')->get('monitor', 'interval', 30);
    }

    public static function workerInterval()
    {
        return (int) AppConfig::module('proactiveha')->get('worker', 'interval', 5);
    }

    public static function maxAttempts()
    {
        return (int) AppConfig::module('proactiveha')->get('worker', 'max_attempts', 6);
    }
}
