<?php

namespace Icinga\Module\Proactiveha\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class Mapping extends Model
{
    public function getTableName()
    {
        return 'proactiveha_mapping';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'vcenter_id',
            'cluster_id',
            'bp_config_name',
            'bp_node_name',
            'vsphere_host_name',
            'vsphere_host_uuid',
            'vsphere_host_moid',
            'uuid_last_resolved',
            'enabled',
            'created_at',
            'updated_at'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('vcenter', Vcenter::class)
            ->setCandidateKey('vcenter_id')
            ->setForeignKey('id');

        $relations->belongsTo('cluster', Cluster::class)
            ->setCandidateKey('cluster_id')
            ->setForeignKey('id')
            ->setJoinType('LEFT');

        $relations->hasOne('state', State::class)
            ->setCandidateKey('id')
            ->setForeignKey('mapping_id');
    }
}
