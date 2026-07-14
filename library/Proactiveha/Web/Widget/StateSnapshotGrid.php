<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\I18n\Translation;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class StateSnapshotGrid extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'div';
    protected $defaultAttributes = ['class' => 'proactiveha-snapshot-grid'];

    private $snapshot;

    public function __construct(array $snapshot)
    {
        $this->snapshot = $snapshot;
    }

    protected function assemble()
    {
        $this->addHtml(Html::tag('h2', $this->translate('Live State Snapshot')));
        $this->addHtml(Html::tag('p', ['class' => 'proactiveha-timestamp'], sprintf(
            $this->translate('Generated at: %s'),
            $this->snapshot['generated_at']
        )));

        if (empty($this->snapshot['rows'])) {
            $this->addHtml(Html::tag('p', $this->translate('No enabled mappings found.')));
            return;
        }

        $table = Html::tag('table', ['class' => 'common-table']);
        $table->addHtml(Html::tag('thead', Html::tag('tr', [
            Html::tag('th', $this->translate('vCenter')),
            Html::tag('th', $this->translate('BP Config / Node')),
            Html::tag('th', $this->translate('Host')),
            Html::tag('th', $this->translate('BP State')),
            Html::tag('th', $this->translate('Desired State')),
            Html::tag('th', $this->translate('vSphere API State')),
            Html::tag('th', $this->translate('Monitored')),
            Html::tag('th', $this->translate('Status'))
        ])));

        $tbody = Html::tag('tbody');

        foreach ($this->snapshot['rows'] as $row) {
            $statusClass = 'status-' . $row['match_status'];
            $statusLabel = $this->statusLabel($row['match_status']);

            $bpCell = Html::tag('td', [
                new Link(
                    $row['bp_config_name'] . ' / ' . $row['bp_node_name'],
                    Url::fromPath('proactiveha/mapping/edit', ['id' => $row['mapping_id']])
                )
            ]);

            $apiState = $row['vsphere_api_state'] !== null
                ? $row['vsphere_api_state']
                : $this->translate('N/A');

            $monitored = $row['monitored']
                ? $this->translate('Yes')
                : $this->translate('No');

            $hostDisplay = $row['host_name'];
            if (!empty($row['host_moid'])) {
                $hostDisplay .= ' (' . $row['host_moid'] . ')';
            }

            $tr = Html::tag('tr', [
                'class' => $statusClass
            ], [
                Html::tag('td', $row['vcenter_name']),
                $bpCell,
                Html::tag('td', $hostDisplay),
                Html::tag('td', $row['bp_state_name']),
                Html::tag('td', $row['desired_state_name']),
                Html::tag('td', $apiState),
                Html::tag('td', $monitored),
                Html::tag('td', ['class' => 'status-cell'], $statusLabel)
            ]);

            if (!empty($row['errors'])) {
                $tr->addHtml(Html::tag('td', [
                    'class' => 'error-detail',
                    'colspan' => '8'
                ], Html::tag('pre', implode("\n", $row['errors']))));
            }

            $tbody->addHtml($tr);
        }

        $table->addHtml($tbody);
        $this->addHtml($table);

        $this->addHtml(
            new Link(
                $this->translate('Back to Dashboard'),
                Url::fromPath('proactiveha/dashboard'),
                ['class' => 'button-link']
            )
        );
    }

    private function statusLabel($status)
    {
        switch ($status) {
            case 'ok':        return $this->translate('OK');
            case 'mismatch':  return $this->translate('Mismatch');
            case 'stale':     return $this->translate('Stale');
            case 'error':     return $this->translate('Error');
            case 'unknown':   return $this->translate('Unknown');
            default:          return $this->translate('Unknown');
        }
    }
}
