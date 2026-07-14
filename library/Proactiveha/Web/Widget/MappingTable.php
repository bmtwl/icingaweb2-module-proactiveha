<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use Icinga\Web\Session;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class MappingTable extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'table';
    protected $defaultAttributes = ['class' => 'common-table proactiveha-mapping-table'];

    private $mappings;

    public function __construct($mappings)
    {
        $this->mappings = $mappings;
    }

    protected function assemble()
    {
        $this->addHtml(Html::tag('thead', Html::tag('tr', [
            Html::tag('th', $this->translate('vCenter')),
            Html::tag('th', $this->translate('BP Config')),
            Html::tag('th', $this->translate('BP Node')),
            Html::tag('th', $this->translate('vSphere Host')),
            Html::tag('th', $this->translate('MOID')),
            Html::tag('th', $this->translate('Desired State')),
            Html::tag('th', $this->translate('Last Pushed')),
            Html::tag('th', $this->translate('Push Status')),
            Html::tag('th', $this->translate('Actions'))
        ])));

        $tbody = Html::tag('tbody');

        foreach ($this->mappings as $mapping) {
            $vcenterName = $mapping->vcenter ? $mapping->vcenter->name : 'N/A';

            $state = $mapping->state;
            $desiredState = $state ? $state->desired_state_name : 'N/A';
            $desiredStateClass = $this->stateBadgeClass($desiredState);
            $lastPushed = $state && $state->last_pushed ? $this->timeAgo($state->last_pushed) : '-';
            $status = $state ? $state->push_status : 'N/A';
            $statusClass = $this->statusBadgeClass($status);

            $tbody->addHtml(Html::tag('tr', [
                Html::tag('td', $vcenterName),
                Html::tag('td', $mapping->bp_config_name),
                Html::tag('td', $mapping->bp_node_name),
                Html::tag('td', $mapping->vsphere_host_name),
                Html::tag('td', $mapping->vsphere_host_moid ?: '-'),
                Html::tag('td', Html::tag('span', ['class' => 'state-badge ' . $desiredStateClass], $desiredState)),
                Html::tag('td', $lastPushed),
                Html::tag('td', Html::tag('span', ['class' => 'state-badge ' . $statusClass], $status)),
                Html::tag('td', $this->buildActions($mapping, $state))
            ]));
        }

        $this->addHtml($tbody);

        $this->addHtml(
            new Link(
                $this->translate('Add Mapping'),
                Url::fromPath('proactiveha/mapping/add'),
                ['class' => 'button-link']
            )
        );
    }

    private function buildActions($mapping, $state)
    {
        $actions = [];
        $csrfToken = Session::getSession()->getId();

        $actions[] = new Link(
            $this->translate('Edit'),
            Url::fromPath('proactiveha/mapping/edit', ['id' => $mapping->id])
        );
        $actions[] = new Text(' ');

        $actions[] = new Link(
            $this->translate('Test'),
            Url::fromPath('proactiveha/mapping/test', ['id' => $mapping->id])
        );
        $actions[] = new Text(' ');

        $actions[] = new Link(
            $this->translate('Logs'),
            Url::fromPath('proactiveha/mapping/logs', ['id' => $mapping->id])
        );
        $actions[] = new Text(' ');

        if ($mapping->enabled && !empty($mapping->vsphere_host_moid)) {
            $actions[] = $this->postLink(
                $this->translate('Push Now'),
                Url::fromPath('proactiveha/mapping/push', ['id' => $mapping->id]),
                $csrfToken
            );
            $actions[] = new Text(' ');
        }

        $actions[] = $this->postLink(
            $this->translate('Resolve MOID'),
            Url::fromPath('proactiveha/mapping/resolve', ['id' => $mapping->id]),
            $csrfToken
        );
        $actions[] = new Text(' ');

        $actions[] = Html::tag('span', ['class' => 'proactiveha-force-state'], $this->translate('Force:'));

        foreach (['green' => 'OK', 'yellow' => 'WARNING', 'red' => 'CRITICAL'] as $forcedState => $label) {
            $actions[] = $this->forceStateLink($mapping->id, $forcedState, $label, $csrfToken);
            $actions[] = new Text(' ');
        }

        return $actions;
    }

    private function postLink($label, Url $url, $csrfToken)
    {
        return Html::tag('form', [
            'method' => 'post',
            'action' => $url->getAbsoluteUrl(),
            'class'  => 'inline-form'
        ], [
            Html::tag('input', [
                'type'  => 'hidden',
                'name'  => 'csrf_token',
                'value' => $csrfToken
            ]),
            Html::tag('button', [
                'type'  => 'submit',
                'class' => 'link-button'
            ], $label)
        ]);
    }

    private function forceStateLink($mappingId, $state, $label, $csrfToken)
    {
        return Html::tag('form', [
            'method' => 'post',
            'action' => Url::fromPath('proactiveha/mapping/force', ['id' => $mappingId])->getAbsoluteUrl(),
            'class'  => 'inline-form'
        ], [
            Html::tag('input', [
                'type'  => 'hidden',
                'name'  => 'csrf_token',
                'value' => $csrfToken
            ]),
            Html::tag('input', [
                'type'  => 'hidden',
                'name'  => 'state',
                'value' => $state
            ]),
            Html::tag('button', [
                'type'  => 'submit',
                'class' => 'state-badge ' . $this->stateBadgeClass($label) . ' force-state-button',
                'title' => sprintf($this->translate('Force state to %s'), $state)
            ], $label)
        ]);
    }

    private function stateBadgeClass($state)
    {
        switch (strtolower($state)) {
            case 'ok':
            case 'green':
            case 'up':
                return 'state-ok';
            case 'warning':
            case 'yellow':
                return 'state-warning';
            case 'critical':
            case 'red':
            case 'down':
                return 'state-critical';
            case 'pending':
                return 'state-pending';
            case 'unknown':
                return 'state-unknown';
            default:
                return 'state-none';
        }
    }

    private function statusBadgeClass($status)
    {
        switch ($status) {
            case 'synced':
                return 'state-ok';
            case 'pending':
            case 'in_progress':
                return 'state-pending';
            case 'failed':
                return 'state-critical';
            case 'blocked':
                return 'state-warning';
            default:
                return 'state-unknown';
        }
    }

    private function timeAgo($timestamp)
    {
        $then = strtotime($timestamp);
        if ($then === false) {
            return $timestamp;
        }

        $diff = time() - $then;
        if ($diff < 60) {
            return $this->translate('Just now');
        }
        if ($diff < 3600) {
            return sprintf($this->translate('%dm ago'), floor($diff / 60));
        }
        if ($diff < 86400) {
            return sprintf($this->translate('%dh ago'), floor($diff / 3600));
        }
        return sprintf($this->translate('%dd ago'), floor($diff / 86400));
    }
}
