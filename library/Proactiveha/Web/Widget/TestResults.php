<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\I18n\Translation;

class TestResults extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'div';
    protected $defaultAttributes = ['class' => 'proactiveha-test-results'];

    private $results;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    protected function assemble()
    {
        $title = $this->results['vcenter'] ?? $this->results['mapping'] ?? $this->translate('Test Results');
        $this->addHtml(Html::tag('h1', $title));

        $table = Html::tag('table', ['class' => 'common-table']);
        $table->addHtml(Html::tag('thead', Html::tag('tr', [
            Html::tag('th', $this->translate('Step')),
            Html::tag('th', $this->translate('Status')),
            Html::tag('th', $this->translate('Detail'))
        ])));

        $tbody = Html::tag('tbody');

        foreach ($this->results['steps'] as $step) {
            $statusClass = 'status-' . $step['status'];
            $detail = empty($step['detail']) ? '-' : Html::tag('pre', $step['detail']);
            $tbody->addHtml(Html::tag('tr', [
                Html::tag('td', $step['name']),
                Html::tag('td', ['class' => $statusClass], $step['status']),
                Html::tag('td', $detail)
            ]));
        }

        $table->addHtml($tbody);
        $this->addHtml($table);

        if (!empty($this->results['hosts'])) {
            $this->addHtml(Html::tag('h2', $this->translate('Discovered Hosts')));
            $hostTable = Html::tag('table', ['class' => 'common-table']);
            $hostTable->addHtml(Html::tag('thead', Html::tag('tr', [
                Html::tag('th', $this->translate('MOID')),
                Html::tag('th', $this->translate('Name')),
                Html::tag('th', $this->translate('DNS Name'))
            ])));

            $hostBody = Html::tag('tbody');
            foreach ($this->results['hosts'] as $host) {
                $hostBody->addHtml(Html::tag('tr', [
                    Html::tag('td', $host['moid'] ?? '-'),
                    Html::tag('td', $host['name'] ?? '-'),
                    Html::tag('td', $host['dnsName'] ?? '-')
                ]));
            }
            $hostTable->addHtml($hostBody);
            $this->addHtml($hostTable);
        }
    }
}
