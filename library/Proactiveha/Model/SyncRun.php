<?php

namespace Icinga\Module\Proactiveha\Model;

use ipl\Orm\Model;

class SyncRun extends Model
{
    public function getTableName()
    {
        return 'proactiveha_sync_run';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'started_at',
            'finished_at',
            'status',
            'mappings_processed',
            'mappings_failed',
            'message'
        ];
    }
}
