<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Worker\SyncAgent;

class SyncCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function runAction()
    {
        $agent = new SyncAgent($this->getDb());
        $agent->run();
    }
}
