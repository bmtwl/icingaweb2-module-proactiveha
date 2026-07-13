<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class ClusterTable extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'table';
    protected $defaultAttributes = ['class' => 'common-table'];

    private $clusters;

    public function __construct($clusters)
    {
        $this->clusters = $clusters;
    }

    protected function assemble()
    {
        $this->addHtml(Html::tag('div', ['class' => 'proactiveha-callout info'], $this->translate(
            'Registering a provider here only adds ESXi hosts as monitored entities. ' .
            'A vCenter administrator must still enable Proactive HA on the cluster and add this provider. ' .
            'This requires the Host.Inventory.EditCluster permission and is intentionally not performed by this module.'
        )));

        $this->addHtml(Html::tag('thead', Html::tag('tr', [
            Html::tag('th', $this->translate('vCenter / Cluster')),
            Html::tag('th', $this->translate('Mode')),
            Html::tag('th', $this->translate('Moderate')),
            Html::tag('th', $this->translate('Severe')),
            Html::tag('th', $this->translate('Provider Enabled')),
            Html::tag('th', $this->translate('Actions'))
        ])));

        $tbody = Html::tag('tbody');

        foreach ($this->clusters as $cluster) {
            $vcenterName = $cluster->vcenter ? $cluster->vcenter->name : 'N/A';
            $displayName = $vcenterName . ' / ' . $cluster->name;

            $actions = [
                new Link(
                    $this->translate('Edit'),
                    Url::fromPath('proactiveha/cluster/edit', ['id' => $cluster->id])
                ),
                new Text(' '),
                new Link(
                    $this->translate('Delete'),
                    Url::fromPath('proactiveha/cluster/delete', ['id' => $cluster->id])
                ),
                new Text(' ')
            ];

            if ($cluster->provider_enabled) {
                $actions[] = new Link(
                    $this->translate('Unregister Provider'),
                    Url::fromPath('proactiveha/cluster/unregister', ['id' => $cluster->id])
                );
            } else {
                $actions[] = new Link(
                    $this->translate('Register Provider'),
                    Url::fromPath('proactiveha/cluster/register', ['id' => $cluster->id])
                );
            }

            $tbody->addHtml(Html::tag('tr', [
                Html::tag('td', $displayName),
                Html::tag('td', $cluster->cluster_mode),
                Html::tag('td', $cluster->moderate_remediation),
                Html::tag('td', $cluster->severe_remediation),
                Html::tag('td', $cluster->provider_enabled ? $this->translate('Yes') : $this->translate('No')),
                Html::tag('td', $actions)
            ]));
        }

        $this->addHtml($tbody);

        $this->addHtml(
            new Link(
                $this->translate('Add Cluster'),
                Url::fromPath('proactiveha/cluster/add'),
                ['class' => 'button-link']
            )
        );
    }
}
