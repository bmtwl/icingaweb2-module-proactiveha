<?php

namespace Icinga\Module\Proactiveha\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Model\State;
use ipl\Stdlib\Filter;

class PendingCommand extends Command
{
    use Database;

    public function init()
    {
        $this->app->getModuleManager()->loadEnabledModules();
    }

    public function indexAction()
    {
        $status = $this->params->get('status', 'pending');

        $query = State::on($this->getDb())
            ->orderBy('updated_at', SORT_DESC);

        if ($status !== 'all') {
            $query->filter(Filter::equal('push_status', $status));
        }

        $rows = $query->execute();

        $count = 0;
        foreach ($rows as $row) {
            $count++;
            echo sprintf(
                "id=%d mapping_id=%d desired=%s vsphere=%s status=%s attempts=%d retry=%s error=%s\n",
                $row->id,
                $row->mapping_id,
                $row->desired_state_name,
                $row->vsphere_state,
                $row->push_status,
                $row->push_attempts,
                $row->retry_at ?: '-',
                $row->last_error ?: '-'
            );
        }

        echo "\nTotal: $count\n";
    }
}
