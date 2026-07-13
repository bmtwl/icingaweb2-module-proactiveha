<?php

namespace Icinga\Module\Proactiveha\Forms;

use Icinga\Web\Notification;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatForm;
use Psr\Http\Message\ServerRequestInterface;

class ConfirmDeleteForm extends CompatForm
{
    /** @var Connection */
    private $db;

    /** @var string */
    private $table;

    /** @var string */
    private $returnUrl;

    /** @var int */
    private $id;

    /** @var bool */
    private $saved = false;

    public function __construct(Connection $db, $table, $redirectUrl, $id)
    {
        $this->db       = $db;
        $this->table    = $table;
        $this->returnUrl = $redirectUrl;
        $this->id       = (int) $id;
    }

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
        $this->addElement('hidden', 'id', ['value' => $this->id]);
        $this->addElement('submit', 'submit', [
            'label' => $this->translate('Delete')
        ]);
    }

    protected function onSuccess()
    {
        try {
            $this->db->delete($this->table, ['id = ?' => $this->id]);
            Notification::success($this->translate('Deleted'));
            $this->saved = true;
        } catch (\Exception $e) {
            Notification::error($e->getMessage());
            $this->saved = false;
        }
    }
}
