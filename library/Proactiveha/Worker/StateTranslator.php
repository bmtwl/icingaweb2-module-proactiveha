<?php

namespace Icinga\Module\Proactiveha\Worker;

class StateTranslator
{
    public static function toVsphereState($icingaState)
    {
        switch ($icingaState) {
            case 0: return 'green';
            case 1: return 'yellow';
            case 2: return 'red';
            default: return null;
        }
    }
}
