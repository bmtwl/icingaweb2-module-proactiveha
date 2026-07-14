<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use Icinga\Web\Session;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\I18n\Translation;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class Dashboard extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'div';
    protected $defaultAttributes = ['class' => 'proactiveha-dashboard'];

    private $metrics;

    public function __construct(array $metrics)
    {
        $this->metrics = $metrics;
    }

    protected function assemble()
    {
        $this->addHtml(Html::tag('h1', $this->translate('Proactive HA Bridge')));

        $this->addHtml($this->buildStatusBanner());
        $this->addHtml($this->buildTileRow());
        $this->addHtml($this->buildAttentionSection());
        $this->addHtml($this->buildStateDistribution());
        $this->addHtml($this->buildRecentActivity());
        $this->addHtml($this->buildActionForms());
    }

    private function buildStatusBanner()
    {
        $overall = $this->computeOverallStatus();

        $classes = ['proactiveha-status-banner', 'status-' . $overall['status']];
        return Html::tag('div', ['class' => implode(' ', $classes)], [
            Html::tag('strong', $overall['label']),
            Html::tag('span', ' ' . $overall['message'])
        ]);
    }

    private function buildTileRow()
    {
        $vcenter = $this->metrics['vcenter'];
        $cluster = $this->metrics['cluster'];
        $mapping = $this->metrics['mapping'];
        $state = $this->metrics['state'];

        $tiles = [
            [
                'label' => $this->translate('vCenter Connections'),
                'value' => $vcenter['total'] ?? 0,
                'sub'   => sprintf(
                    $this->translate('%d enabled, %d with provider'),
                    $vcenter['enabled'] ?? 0,
                    $vcenter['provider_registered'] ?? 0
                ),
                'status' => ($vcenter['provider_missing'] ?? 0) > 0 ? 'warning' : 'ok',
                'link'  => Url::fromPath('proactiveha/config')
            ],
            [
                'label' => $this->translate('Clusters'),
                'value' => $cluster['total'] ?? 0,
                'sub'   => sprintf(
                    $this->translate('%d enabled, %d provider enabled'),
                    $cluster['enabled'] ?? 0,
                    $cluster['provider_enabled'] ?? 0
                ),
                'status' => ($cluster['provider_disabled'] ?? 0) > 0 ? 'warning' : 'ok',
                'link'  => Url::fromPath('proactiveha/cluster')
            ],
            [
                'label' => $this->translate('Mappings'),
                'value' => $mapping['total'] ?? 0,
                'sub'   => sprintf(
                    $this->translate('%d enabled, %d with MOID'),
                    $mapping['enabled'] ?? 0,
                    $mapping['with_moid'] ?? 0
                ),
                'status' => ($mapping['without_moid'] ?? 0) > 0 ? 'warning' : 'ok',
                'link'  => Url::fromPath('proactiveha/mapping')
            ],
            [
                'label' => $this->translate('Pending Pushes'),
                'value' => ($state['pending'] ?? 0) + ($state['in_progress'] ?? 0),
                'sub'   => $this->translate('Waiting to be pushed to vCenter'),
                'status' => (($state['pending'] ?? 0) + ($state['in_progress'] ?? 0)) > 0 ? 'warning' : 'ok',
                'link'  => Url::fromPath('proactiveha/log')
            ],
            [
                'label' => $this->translate('Failed Pushes'),
                'value' => $state['failed'] ?? 0,
                'sub'   => $this->translate('Require attention'),
                'status' => ($state['failed'] ?? 0) > 0 ? 'critical' : 'ok',
                'link'  => Url::fromPath('proactiveha/log')
            ]
        ];

        $row = Html::tag('div', ['class' => 'proactiveha-tile-row']);

        foreach ($tiles as $tile) {
            $row->addHtml(
                Html::tag('a', [
                    'href'  => $tile['link']->getAbsoluteUrl(),
                    'class' => 'proactiveha-tile status-' . $tile['status']
                ], [
                    Html::tag('h2', (string) $tile['value']),
                    Html::tag('h3', $tile['label']),
                    Html::tag('p', $tile['sub'])
                ])
            );
        }

        return $row;
    }

    private function buildAttentionSection()
    {
        $items = $this->collectAttentionItems();

        if (empty($items)) {
            return Html::tag('div', ['class' => 'proactiveha-section'], [
                Html::tag('h2', $this->translate('Attention Required')),
                Html::tag('p', ['class' => 'proactiveha-ok'], $this->translate('No issues detected.'))
            ]);
        }

        $list = Html::tag('ul', ['class' => 'proactiveha-attention-list']);
        foreach ($items as $item) {
            $list->addHtml(Html::tag('li', [
                'class' => 'attention-' . $item['severity']
            ], [
                new Link($item['message'], $item['url'])
            ]));
        }

        return Html::tag('div', ['class' => 'proactiveha-section'], [
            Html::tag('h2', $this->translate('Attention Required')),
            $list
        ]);
    }

    private function buildStateDistribution()
    {
        $state = $this->metrics['state'];

        $total = max(1, ($state['green'] ?? 0) + ($state['yellow'] ?? 0) + ($state['red'] ?? 0));
        $greenPct = round((($state['green'] ?? 0) / $total) * 100);
        $yellowPct = round((($state['yellow'] ?? 0) / $total) * 100);
        $redPct = 100 - $greenPct - $yellowPct;

        return Html::tag('div', ['class' => 'proactiveha-section proactiveha-state-distribution'], [
            Html::tag('h2', $this->translate('State Distribution')),
            Html::tag('div', ['class' => 'state-bar'], [
                Html::tag('div', [
                    'class' => 'state-segment state-green',
                    'style' => "width: {$greenPct}%",
                    'title' => sprintf($this->translate('Green: %d'), $state['green'] ?? 0)
                ]),
                Html::tag('div', [
                    'class' => 'state-segment state-yellow',
                    'style' => "width: {$yellowPct}%",
                    'title' => sprintf($this->translate('Yellow: %d'), $state['yellow'] ?? 0)
                ]),
                Html::tag('div', [
                    'class' => 'state-segment state-red',
                    'style' => "width: {$redPct}%",
                    'title' => sprintf($this->translate('Red: %d'), $state['red'] ?? 0)
                ])
            ]),
            Html::tag('div', ['class' => 'state-legend'], [
                Html::tag('span', ['class' => 'legend-green'], sprintf($this->translate('Green: %d'), $state['green'] ?? 0)),
                Html::tag('span', ['class' => 'legend-yellow'], sprintf($this->translate('Yellow: %d'), $state['yellow'] ?? 0)),
                Html::tag('span', ['class' => 'legend-red'], sprintf($this->translate('Red: %d'), $state['red'] ?? 0))
            ])
        ]);
    }

    private function buildRecentActivity()
    {
        $sync = $this->metrics['sync'];
        $logs = $this->metrics['logs'];

        $container = Html::tag('div', ['class' => 'proactiveha-section proactiveha-recent-activity']);

        $container->addHtml(Html::tag('h2', $this->translate('Recent Activity (24h)')));

        $lastRun = $sync['last_run'] ?? null;
        if ($lastRun) {
            $statusClass = 'status-' . $lastRun->status;
            $container->addHtml(Html::tag('p', [
                'class' => 'proactiveha-sync-status ' . $statusClass
            ], sprintf(
                $this->translate('Last sync: %s (%s, %d processed, %d failed)'),
                $lastRun->started_at,
                $lastRun->status,
                $lastRun->mappings_processed,
                $lastRun->mappings_failed
            )));
        } else {
            $container->addHtml(Html::tag('p', $this->translate('No sync runs recorded yet.')));
        }

        $nonInfo = $logs['recent_non_info'] ?? [];
        if (!empty($nonInfo)) {
            $list = Html::tag('ul', ['class' => 'proactiveha-log-list']);
            foreach ($nonInfo as $log) {
                $class = 'log-' . $log->level;
                $list->addHtml(Html::tag('li', [
                    'class' => $class
                ], sprintf(
                    '[%s] %s: %s',
                    $log->timestamp,
                    $log->event_type,
                    $log->message
                )));
            }
            $container->addHtml($list);
        } else {
            $container->addHtml(Html::tag('p', ['class' => 'proactiveha-ok'], $this->translate('No warnings or errors in the last 24 hours.')));
        }

        return $container;
    }

    private function buildActionForms()
    {
        $syncForm = Html::tag('form', [
            'method' => 'post',
            'action' => Url::fromPath('proactiveha/sync/now')->getAbsoluteUrl(),
            'class'  => 'proactiveha-sync-form'
        ], [
            Html::tag('input', [
                'type'  => 'hidden',
                'name'  => 'csrf_token',
                'value' => Session::getSession()->getId()
            ]),
            Html::tag('button', [
                'type'  => 'submit',
                'class' => 'button-link'
            ], $this->translate('Sync Now'))
        ]);

        $snapshotForm = Html::tag('form', [
            'method' => 'post',
            'action' => Url::fromPath('proactiveha/dashboard/snapshot')->getAbsoluteUrl(),
            'class'  => 'proactiveha-snapshot-form'
        ], [
            Html::tag('input', [
                'type'  => 'hidden',
                'name'  => 'csrf_token',
                'value' => Session::getSession()->getId()
            ]),
            Html::tag('button', [
                'type'  => 'submit',
                'class' => 'button-link'
            ], $this->translate('Get Detailed State'))
        ]);

        return Html::tag('div', ['class' => 'proactiveha-actions'], [
            $syncForm,
            $snapshotForm
        ]);
    }

    private function computeOverallStatus()
    {
        $state = $this->metrics['state'];
        $stale = $state['stale'] ?? 0;

        if (($state['failed'] ?? 0) > 0) {
            return [
                'status'  => 'critical',
                'label'   => $this->translate('Critical'),
                'message' => $this->translate('One or more mappings failed to push.')
            ];
        }

        if (($state['pending'] ?? 0) > 0 || ($state['in_progress'] ?? 0) > 0) {
            return [
                'status'  => 'warning',
                'label'   => $this->translate('Warning'),
                'message' => $this->translate('Pushes are pending.')
            ];
        }

        if (($this->metrics['vcenter']['provider_missing'] ?? 0) > 0) {
            return [
                'status'  => 'warning',
                'label'   => $this->translate('Warning'),
                'message' => $this->translate('One or more vCenters lack a registered provider.')
            ];
        }

        if (($this->metrics['mapping']['without_moid'] ?? 0) > 0) {
            return [
                'status'  => 'warning',
                'label'   => $this->translate('Warning'),
                'message' => $this->translate('One or more mappings lack a host MOID.')
            ];
        }

        if ($stale > 0) {
            return [
                'status'  => 'warning',
                'label'   => $this->translate('Warning'),
                'message' => $this->translate('One or more states are stale.')
            ];
        }

        return [
            'status'  => 'ok',
            'label'   => $this->translate('OK'),
            'message' => $this->translate('All systems operational.')
        ];
    }

    private function collectAttentionItems()
    {
        $items = [];
        $state = $this->metrics['state'];
        $stale = $state['stale'] ?? 0;

        foreach (($this->metrics['vcenter']['_unregistered'] ?? []) as $vcenter) {
            $items[] = [
                'severity' => 'warning',
                'message'  => sprintf(
                    $this->translate('vCenter "%s" has no registered provider'),
                    $vcenter->name
                ),
                'url'      => Url::fromPath('proactiveha/config/test', ['id' => $vcenter->id])
            ];
        }

        foreach (($this->metrics['cluster']['_provider_disabled'] ?? []) as $cluster) {
            $vcenterName = $cluster->vcenter ? $cluster->vcenter->name : 'N/A';
            $items[] = [
                'severity' => 'warning',
                'message'  => sprintf(
                    $this->translate('Cluster "%s / %s" is enabled but provider is not enabled'),
                    $vcenterName,
                    $cluster->name
                ),
                'url'      => Url::fromPath('proactiveha/cluster')
            ];
        }

        foreach (($this->metrics['mapping']['_without_moid'] ?? []) as $mapping) {
            $vcenterName = $mapping->vcenter ? $mapping->vcenter->name : 'N/A';
            $items[] = [
                'severity' => 'warning',
                'message'  => sprintf(
                    $this->translate('Mapping "%s / %s / %s" has no host MOID'),
                    $vcenterName,
                    $mapping->bp_config_name,
                    $mapping->bp_node_name
                ),
                'url'      => Url::fromPath('proactiveha/mapping/edit', ['id' => $mapping->id])
            ];
        }

        if (($state['failed'] ?? 0) > 0) {
            $items[] = [
                'severity' => 'critical',
                'message'  => sprintf(
                    $this->translate('%d mapping(s) failed to push'),
                    $state['failed']
                ),
                'url'      => Url::fromPath('proactiveha/log')
            ];
        }

        if ($stale > 0) {
            $items[] = [
                'severity' => 'warning',
                'message'  => sprintf(
                    $this->translate('%d mapping(s) have stale state'),
                    $stale
                ),
                'url'      => Url::fromPath('proactiveha/log')
            ];
        }

        return $items;
    }
}
