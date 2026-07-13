<?php

namespace Icinga\Module\Proactiveha\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class Vcenter extends Model
{
    public function getTableName()
    {
        return 'proactiveha_vcenter';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'name',
            'url',
            'username',
            'password',
            'verify_ssl',
            'api_version',
            'provider_key',
            'provider_registered',
            'last_connection',
            'last_session_refresh',
            'enabled',
            'created_at',
            'updated_at'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('mappings', Mapping::class)
            ->setCandidateKey('id')
            ->setForeignKey('vcenter_id');
    }
}
