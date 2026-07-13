<?php

namespace Icinga\Module\Proactiveha\Forms;

use Icinga\Module\Proactiveha\Crypto\PasswordEncryptor;
use Icinga\Module\Proactiveha\Util\Config as ModuleConfig;
use Icinga\Web\Notification;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatForm;
use Psr\Http\Message\ServerRequestInterface;

class VcenterForm extends CompatForm
{
    /** @var Connection */
    private $db;

    /** @var int|null */
    private $id;

    /** @var bool */
    private $saved = false;

    public function __construct(Connection $db, $id = null)
    {
        $this->db = $db;
        $this->id = $id !== null ? (int) $id : null;
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
        $this->addElement('text', 'name', [
            'label'    => $this->translate('Name'),
            'required' => true
        ]);

        $this->addElement('text', 'url', [
            'label'       => $this->translate('vCenter URL'),
            'description' => $this->translate('Example: https://vcenter.example.com/sdk'),
            'required'    => true
        ]);

        $this->addElement('text', 'username', [
            'label'    => $this->translate('Username'),
            'required' => true
        ]);

        $passwordOptions = [
            'label'    => $this->translate('Password'),
            'required' => $this->id === null
        ];
        if ($this->id !== null) {
            $passwordOptions['description'] = $this->translate('Leave empty to keep current password');
        }
        $this->addElement('password', 'password', $passwordOptions);

        $this->addElement('select', 'verify_ssl', [
            'label'       => $this->translate('Verify SSL'),
            'description' => $this->translate('Disable for internal/self-signed CAs'),
            'required'    => true,
            'options'     => [
                '1' => $this->translate('Yes'),
                '0' => $this->translate('No')
            ]
        ]);

        $this->addElement('select', 'enabled', [
            'label'    => $this->translate('Enabled'),
            'required' => true,
            'options'  => [
                '1' => $this->translate('Yes'),
                '0' => $this->translate('No')
            ]
        ]);

        $this->addElement('submit', 'submit', [
            'label' => $this->translate('Save')
        ]);
    }

    protected function onSuccess()
    {
        try {
            $values = $this->getValues();

            $url = $values['url'];
            if (!filter_var($url, FILTER_VALIDATE_URL)
                || stripos($url, 'https://') !== 0
            ) {
                throw new \RuntimeException($this->translate('vCenter URL must be a valid HTTPS URL'));
            }

            $keyFile = ModuleConfig::keyFile();
            if (!file_exists($keyFile)) {
                PasswordEncryptor::generateKey($keyFile);
            }

            $data = [
                'name'       => $values['name'],
                'url'        => $url,
                'username'   => $values['username'],
                'verify_ssl' => (int) $values['verify_ssl'],
                'enabled'    => (int) $values['enabled'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($this->id === null) {
                $data['password']   = PasswordEncryptor::encrypt($values['password'], $keyFile);
                $data['created_at'] = date('Y-m-d H:i:s');
                $this->db->insert('proactiveha_vcenter', $data);
                $message = $this->translate('vCenter connection created');
            } else {
                if (!empty($values['password'])) {
                    $data['password'] = PasswordEncryptor::encrypt($values['password'], $keyFile);
                }
                $this->db->update('proactiveha_vcenter', $data, ['id = ?' => $this->id]);
                $message = $this->translate('vCenter connection updated');
            }

            Notification::success($message);
            $this->saved = true;
        } catch (\Exception $e) {
            Notification::error($e->getMessage());
            $this->saved = false;
        }
    }
}
