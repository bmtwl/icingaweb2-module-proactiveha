<?php

namespace Icinga\Module\Proactiveha\Web\Widget;

use Icinga\Web\Session;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\I18n\Translation;

class DashboardTiles extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'div';
    protected $defaultAttributes = ['class' => 'proactiveha-dashboard'];

    private $counts;

    public function __construct(array $counts)
    {
        $this->counts = $counts;
    }

    protected function assemble()
    {
        $this->addHtml(Html::tag('h1', $this->translate('Proactive HA Bridge')));

        $tiles = [
            'vcenterCount' => $this->translate('vCenter Connections'),
            'mappingCount' => $this->translate('Mappings'),
            'pendingCount' => $this->translate('Pending Pushes'),
            'failedCount' => $this->translate('Failed Pushes')
        ];

        foreach ($tiles as $key => $label) {
            $this->addHtml(Html::tag('div', ['class' => 'tile'], [
                Html::tag('h2', (string) ($this->counts[$key] ?? 0)),
                Html::tag('p', $label)
            ]));
        }

        $syncForm = Html::tag('form', [
            'method' => 'post',
            'action' => \ipl\Web\Url::fromPath('proactiveha/sync/now')->getAbsoluteUrl(),
            'class'  => 'proactiveha-sync-form'
        ], [
            Html::tag('input', [
                'type'  => 'hidden',
                'name'  => 'csrf_token',
                'value' => Session::getSession()->getId()
            ]),
            Html::tag('button', ['type' => 'submit', 'class' => 'button-link'], $this->translate('Sync Now'))
        ]);

        $this->addHtml($syncForm);
    }
}
