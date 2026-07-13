<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use Icinga\Module\Proactiveha\Util\ProviderId;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class VcenterTable extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'table';
    protected $defaultAttributes = ['class' => 'common-table'];

    private $vcenters;

    public function __construct($vcenters)
    {
        $this->vcenters = $vcenters;
    }

    protected function assemble()
    {
        $this->addHtml(Html::tag('thead', Html::tag('tr', [
            Html::tag('th', $this->translate('Name')),
            Html::tag('th', $this->translate('URL')),
            Html::tag('th', $this->translate('Username')),
            Html::tag('th', $this->translate('Enabled')),
            Html::tag('th', $this->translate('Provider Registered')),
            Html::tag('th', $this->translate('Provider ID')),
            Html::tag('th', $this->translate('Actions'))
        ])));

        $tbody = Html::tag('tbody');

        foreach ($this->vcenters as $vcenter) {
            $providerRegistered = (bool) $vcenter->provider_registered;
            $providerId = $providerRegistered && !empty($vcenter->provider_key)
                ? ProviderId::toUuid($vcenter->provider_key)
                : '-';

            $tbody->addHtml(Html::tag('tr', [
                Html::tag('td', $vcenter->name),
                Html::tag('td', $vcenter->url),
                Html::tag('td', $vcenter->username),
                Html::tag('td', $vcenter->enabled ? $this->translate('Yes') : $this->translate('No')),
                Html::tag('td', $providerRegistered ? $this->translate('Yes') : $this->translate('No')),
                Html::tag('td', $providerId),
                Html::tag('td', [
                    new Link(
                        $this->translate('Edit'),
                        Url::fromPath('proactiveha/config/edit', ['id' => $vcenter->id])
                    ),
                    new Text(' '),
                    new Link(
                        $this->translate('Delete'),
                        Url::fromPath('proactiveha/config/delete', ['id' => $vcenter->id])
                    ),
                    new Text(' '),
                    new Link(
                        $this->translate('Test'),
                        Url::fromPath('proactiveha/config/test', ['id' => $vcenter->id])
                    )
                ])
            ]));
        }

        $this->addHtml($tbody);

        $this->addHtml(
            new Link(
                $this->translate('Add vCenter'),
                Url::fromPath('proactiveha/config/add'),
                ['class' => 'button-link']
            )
        );
    }
}
