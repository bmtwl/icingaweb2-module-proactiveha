<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Worker\QueueWorker;

class WorkerCommand extends Command
{
    use Database;

    public function runAction()
    {
        $once = (bool) $this->params->get('once', false);
        $db = $this->getDb();

        $db->update('proactiveha_state', [
            'push_status' => 'pending',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['push_status = ?' => 'in_progress']);

        $worker = new QueueWorker($db, $once);
        $worker->run();
    }
}
