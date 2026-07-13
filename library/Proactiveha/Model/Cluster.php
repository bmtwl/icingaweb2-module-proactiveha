<?php

namespace Icinga\Module\Proactiveha\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class Cluster extends Model
{
    public function getTableName()
    {
        return 'proactiveha_cluster';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'vcenter_id',
            'mo_id',
            'name',
            'enabled',
            'cluster_mode',
            'moderate_remediation',
            'severe_remediation',
            'provider_enabled',
            'last_enabled_at',
            'last_disabled_at',
            'last_error',
            'created_at',
            'updated_at'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('vcenter', Vcenter::class)
            ->setCandidateKey('vcenter_id')
            ->setForeignKey('id');
    }
}
