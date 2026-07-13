<?php

namespace Icinga\Module\Proactiveha\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class State extends Model
{
    public function getTableName()
    {
        return 'proactiveha_state';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'mapping_id',
            'desired_state',
            'desired_state_name',
            'vsphere_state',
            'last_evaluated',
            'last_pushed',
            'push_status',
            'push_attempts',
            'retry_at',
            'last_error',
            'updated_at'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('mapping', Mapping::class)
            ->setCandidateKey('mapping_id')
            ->setForeignKey('id');
    }
}
