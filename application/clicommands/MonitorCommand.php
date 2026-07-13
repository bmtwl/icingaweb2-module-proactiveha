<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Worker\StateMonitor;

class MonitorCommand extends Command
{
    use Database;

    public function runAction()
    {
        $once = (bool) $this->params->get('once', false);
        $monitor = new StateMonitor($this->getDb(), $once);
        $monitor->run();
    }
}
