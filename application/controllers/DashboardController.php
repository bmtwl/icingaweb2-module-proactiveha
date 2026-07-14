<?php

namespace Icinga\Module\Proactiveha\Controllers;

use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Util\DashboardData;
use Icinga\Module\Proactiveha\Util\LiveStateSnapshot;
use Icinga\Module\Proactiveha\Web\Widget\Dashboard;
use Icinga\Module\Proactiveha\Web\Widget\StateSnapshotGrid;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class DashboardController extends CompatController
{
    use Database;

    public function init()
    {
        $this->assertPermission('proactiveha/admin');
    }

    public function indexAction()
    {
        $metrics = (new DashboardData($this->getDb(), 60))->getMetrics();
        $this->addContent(new Dashboard($metrics));
    }

    public function snapshotAction()
    {
        if ($this->getServerRequest()->getMethod() !== 'POST') {
            throw new \Icinga\Exception\Http\HttpException(405, $this->translate('Snapshot must be triggered via POST'));
        }

        $body = $this->getServerRequest()->getParsedBody() ?? [];
        $token = $body['csrf_token'] ?? '';

        if ($token !== Session::getSession()->getId()) {
            throw new \Icinga\Exception\Http\HttpException(403, $this->translate('Invalid CSRF token'));
        }

        try {
            $snapshot = (new LiveStateSnapshot($this->getDb()))->capture();
            $this->addContent(new StateSnapshotGrid($snapshot));
        } catch (\Exception $e) {
            Notification::error($e->getMessage());
            $this->redirectNow(Url::fromPath('proactiveha/dashboard'));
        }
    }
}
