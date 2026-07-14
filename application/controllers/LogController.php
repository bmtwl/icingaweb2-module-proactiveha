<?php

namespace Icinga\Module\Proactiveha\Controllers;

use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Common\RestrictionFilter;
use Icinga\Module\Proactiveha\Model\Log;
use Icinga\Module\Proactiveha\Web\Widget\LogTable;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class LogController extends CompatController
{
    use Database;
    use RestrictionFilter;

    public function init()
    {
        $this->assertPermission('proactiveha/admin');
    }

    public function indexAction()
    {
        $params = $this->getServerRequest()->getQueryParams();
        $level = isset($params['level']) && $params['level'] !== '' ? $params['level'] : null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $this->view->title = $this->translate('Event Log');

        $this->addControl($this->buildLevelFilter($level));

        $query = Log::on($this->getDb())
            ->with(['mapping', 'vcenter'])
            ->orderBy('timestamp', SORT_DESC)
            ->limit($limit + 1)
            ->offset($offset);

        if ($level !== null) {
            $query->filter(Filter::equal('level', $level));
        }

        $this->applyRestrictions($query, 'vcenter');

        $logs = iterator_to_array($query->execute());
        $hasMore = count($logs) > $limit;
        $logs = array_slice($logs, 0, $limit);

        $this->addContent(new LogTable($logs));

        $this->addControl($this->buildPagination($page, $hasMore, $level));
    }

    private function buildLevelFilter($activeLevel)
    {
        $levels = [
            null      => $this->translate('All'),
            'debug'   => $this->translate('Debug'),
            'info'    => $this->translate('Info'),
            'warning' => $this->translate('Warning'),
            'error'   => $this->translate('Error')
        ];

        $items = [];
        foreach ($levels as $value => $label) {
            $urlParams = [];
            if ($value !== null) {
                $urlParams['level'] = $value;
            }

            $isActive = $activeLevel === $value;
            $class = $isActive ? 'state-badge state-ok' : 'state-badge state-none';

            $items[] = Html::tag('span', ['class' => 'state-badge-wrapper'], [
                Html::tag('a', [
                    'href' => Url::fromPath('proactiveha/log', $urlParams)->getAbsoluteUrl(),
                    'class' => $isActive ? 'active' : null
                ], Html::tag('span', ['class' => $class], $label))
            ]);
        }

        return Html::tag('ul', ['class' => 'state-badges'], $items);
    }

    private function buildPagination($page, $hasMore, $level)
    {
        $pagination = [];
        $baseParams = $level !== null ? ['level' => $level] : [];

        if ($page > 1) {
            $pagination[] = new Link(
                $this->translate('Previous'),
                Url::fromPath('proactiveha/log', array_merge($baseParams, ['page' => $page - 1]))
            );
        }
        if ($hasMore) {
            $pagination[] = new Link(
                $this->translate('Next'),
                Url::fromPath('proactiveha/log', array_merge($baseParams, ['page' => $page + 1]))
            );
        }

        if (empty($pagination)) {
            return Html::tag('span');
        }

        return Html::tag('div', ['class' => 'proactiveha-pagination'], $pagination);
    }
}
