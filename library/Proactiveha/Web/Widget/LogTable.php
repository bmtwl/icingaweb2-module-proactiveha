<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\I18n\Translation;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class LogTable extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'table';
    protected $defaultAttributes = ['class' => 'common-table proactiveha-log-table'];

    private $logs;

    public function __construct($logs)
    {
        $this->logs = $logs;
    }

    protected function assemble()
    {
        $this->addHtml(Html::tag('thead', Html::tag('tr', [
            Html::tag('th', $this->translate('Timestamp')),
            Html::tag('th', $this->translate('Level')),
            Html::tag('th', $this->translate('vCenter')),
            Html::tag('th', $this->translate('Mapping')),
            Html::tag('th', $this->translate('Event')),
            Html::tag('th', $this->translate('Message'))
        ])));

        $tbody = Html::tag('tbody');

        foreach ($this->logs as $log) {
            $vcenter = $log->vcenter ? $log->vcenter->name : 'N/A';
            $mapping = $log->mapping ? $log->mapping->bp_node_name : 'N/A';

            $levelClass = $this->levelBadgeClass($log->level);

            $mappingLink = $log->mapping
                ? new Link(
                    $mapping,
                    Url::fromPath('proactiveha/mapping/edit', ['id' => $log->mapping_id])
                )
                : $mapping;

            $tbody->addHtml(Html::tag('tr', [
                Html::tag('td', Html::tag('span', ['class' => 'log-timestamp'], $log->timestamp)),
                Html::tag('td', Html::tag('span', ['class' => 'state-badge ' . $levelClass], strtoupper($log->level))),
                Html::tag('td', $vcenter),
                Html::tag('td', $mappingLink),
                Html::tag('td', Html::tag('code', $log->event_type)),
                Html::tag('td', $log->message)
            ]));
        }

        $this->addHtml($tbody);
    }

    private function levelBadgeClass($level)
    {
        switch ($level) {
            case 'debug':   return 'state-none';
            case 'info':    return 'state-ok';
            case 'warning': return 'state-warning';
            case 'error':   return 'state-critical';
            default:        return 'state-unknown';
        }
    }
}
