<?php

namespace Icinga\Module\Proactiveha\Controllers;

use Icinga\Module\Proactiveha\Common\Database;
use Icinga\Module\Proactiveha\Worker\SyncAgent;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class SyncController extends CompatController
{
    use Database;

    public function init()
    {
        $this->assertPermission('proactiveha/admin');
    }

    public function nowAction()
    {
        if ($this->getServerRequest()->getMethod() !== 'POST') {
            throw new \Icinga\Exception\Http\HttpException(405, $this->translate('Sync must be triggered via POST'));
        }

        $body = $this->getServerRequest()->getParsedBody() ?? [];
        $token = $body['csrf_token'] ?? '';

        if ($token !== Session::getSession()->getId()) {
            throw new \Icinga\Exception\Http\HttpException(403, $this->translate('Invalid CSRF token'));
        }

        try {
            $agent = new SyncAgent($this->getDb());
            $agent->run();
            Notification::success($this->translate('Sync completed successfully'));
        } catch (\Exception $e) {
            Notification::error($e->getMessage());
        }

        $this->redirectNow(Url::fromPath('proactiveha/dashboard'));
    }
}
