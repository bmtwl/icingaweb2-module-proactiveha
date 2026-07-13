<?php

namespace Icinga\Module\Proactiveha\Forms\Config;

use Icinga\Application\Config;
use Icinga\Data\ResourceFactory;
use Icinga\Web\Notification;
use ipl\Web\Compat\CompatForm;
use Psr\Http\Message\ServerRequestInterface;

class DatabaseConfigForm extends CompatForm
{
    /** @var bool */
    private $saved = false;

    public function wasSaved(): bool
    {
        return $this->saved;
    }

    public function handleRequest(ServerRequestInterface $request)
    {
        $this->ensureAssembled();
        return parent::handleRequest($request);
    }

    protected function assemble()
    {
        $resources = ResourceFactory::getResourceConfigs('db')->keys();
        $resourceOptions = empty($resources) ? [] : array_combine($resources, $resources);

        $this->addElement('select', 'database_resource', [
            'label'       => $this->translate('Database Resource'),
            'description' => $this->translate('Database resource for Proactive HA'),
            'required'    => true,
            'options'     => array_merge(
                ['' => $this->translate('Please choose')],
                $resourceOptions
            )
        ]);

        $this->addElement('submit', 'submit', [
            'label' => $this->translate('Save Changes')
        ]);
    }

    protected function onSuccess()
    {
        try {
            Config::module('proactiveha')
                ->setSection('database', ['resource' => $this->getValue('database_resource')])
                ->saveIni();

            Notification::success($this->translate('Configuration saved'));
            $this->saved = true;
        } catch (\Exception $e) {
            Notification::error($e->getMessage());
            $this->saved = false;
        }
    }
}
