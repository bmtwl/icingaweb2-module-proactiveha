<?php

$this->providePermission('proactiveha/admin', $this->translate('Administer Proactive HA Bridge'));
$this->provideRestriction('proactiveha/filter', $this->translate('Restrict access to configured vCenters'));

$this->provideConfigTab('database', [
    'title' => $this->translate('Configure database'),
    'label' => $this->translate('Database'),
    'url' => 'config/database'
]);


$section = $this->menuSection('Proactive HA', [
    'url' => 'proactiveha/dashboard',
    'icon' => 'flapping',
    'priority' => 50
]);

$section->add('Dashboard', [
    'url' => 'proactiveha/dashboard'
]);

$section->add('Mappings', [
    'url' => 'proactiveha/mapping'
]);

$section->add('Clusters', [
    'url' => 'proactiveha/cluster'
]);

$section->add('vCenter Connections', [
    'url' => 'proactiveha/config'
]);

$section->add('Logs', [
    'url' => 'proactiveha/log'
]);
