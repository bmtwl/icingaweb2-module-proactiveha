<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

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
    protected $defaultAttributes = ['class' => 'common-table'];

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
            Html::tag('th', $this->translate('Status')),
            Html::tag('th', $this->translate('Actions'))
        ])));

        $tbody = Html::tag('tbody');

        foreach ($this->mappings as $mapping) {
            $vcenterName = $mapping->vcenter ? $mapping->vcenter->name : 'N/A';

            $state = $mapping->state;
            $desiredState = $state ? $state->desired_state_name : 'N/A';
            $lastPushed = $state && $state->last_pushed ? $state->last_pushed : '-';
            $status = $state ? $state->push_status : 'N/A';

            $tbody->addHtml(Html::tag('tr', [
                Html::tag('td', $vcenterName),
                Html::tag('td', $mapping->bp_config_name),
                Html::tag('td', $mapping->bp_node_name),
                Html::tag('td', $mapping->vsphere_host_name),
                Html::tag('td', $mapping->vsphere_host_moid ?: '-'),
                Html::tag('td', $desiredState),
                Html::tag('td', $lastPushed),
                Html::tag('td', $status),
                Html::tag('td', [
                    new Link(
                        $this->translate('Edit'),
                        Url::fromPath('proactiveha/mapping/edit', ['id' => $mapping->id])
                    ),
                    new Text(' '),
                    new Link(
                        $this->translate('Delete'),
                        Url::fromPath('proactiveha/mapping/delete', ['id' => $mapping->id])
                    ),
                    new Text(' '),
                    new Link(
                        $this->translate('Test'),
                        Url::fromPath('proactiveha/mapping/test', ['id' => $mapping->id])
                    )
                ])
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
}
