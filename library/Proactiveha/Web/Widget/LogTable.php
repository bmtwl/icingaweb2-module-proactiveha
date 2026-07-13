<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\I18n\Translation;

class LogTable extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'table';
    protected $defaultAttributes = ['class' => 'common-table'];

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

            $tbody->addHtml(Html::tag('tr', [
                Html::tag('td', $log->timestamp),
                Html::tag('td', $log->level),
                Html::tag('td', $vcenter),
                Html::tag('td', $mapping),
                Html::tag('td', $log->event_type),
                Html::tag('td', $log->message)
            ]));
        }

        $this->addHtml($tbody);
    }
}
