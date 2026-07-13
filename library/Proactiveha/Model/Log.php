<?php

namespace Icinga\Module\Proactiveha\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class Log extends Model
{
    public function getTableName()
    {
        return 'proactiveha_log';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'mapping_id',
            'vcenter_id',
            'timestamp',
            'level',
            'event_type',
            'message',
            'context'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('mapping', Mapping::class)
            ->setCandidateKey('mapping_id')
            ->setForeignKey('id')
            ->setJoinType('LEFT');

        $relations->belongsTo('vcenter', Vcenter::class)
            ->setCandidateKey('vcenter_id')
            ->setForeignKey('id')
            ->setJoinType('LEFT');
    }
}
