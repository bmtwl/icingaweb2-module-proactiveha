<?php

namespace Icinga\Module\Proactiveha\Controllers;

use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Model\Vcenter;
use Icinga\Module\Proactiveha\Web\Widget\DashboardTiles;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class DashboardController extends CompatController
{
    use Database;

    public function init()
    {
        $this->assertPermission('proactiveha/admin');
    }

    public function indexAction()
    {
        $db = $this->getDb();

        $vcenterCount = iterator_count(Vcenter::on($db)->execute());
        $mappingCount = iterator_count(Mapping::on($db)->execute());
        $pendingCount = iterator_count(State::on($db)
            ->filter(Filter::equal('push_status', 'pending'))
            ->execute());
        $failedCount = iterator_count(State::on($db)
            ->filter(Filter::equal('push_status', 'failed'))
            ->execute());

        $this->addContent(new DashboardTiles([
            'vcenterCount' => $vcenterCount,
            'mappingCount' => $mappingCount,
            'pendingCount' => $pendingCount,
            'failedCount' => $failedCount
        ]));
    }
}
