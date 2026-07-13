<?php

namespace Icinga\Module\Proactiveha\Client;

use InvalidArgumentException;
use Icinga\Module\Proactiveha\Util\ProviderId;
use RuntimeException;
use SoapClient;
use SoapFault;

class ProactivehaSoapClient extends SoapClient
{
    /** @var array|null */
    public $pendingHealthUpdate;

    public function __doRequest($request, $location, $action, $version, $oneWay = false)
    {
        if ($this->pendingHealthUpdate !== null && strpos($request, 'PostHealthUpdates') !== false) {
            $request = $this->rewritePostHealthUpdates($request);
        }

        return parent::__doRequest($request, $location, $action, $version, $oneWay);
    }

    private function rewritePostHealthUpdates($request)
    {
        $update = $this->pendingHealthUpdate;

        $providerId = ProviderId::normalize($update['providerId']);
        $moid = htmlspecialchars($update['moid'], ENT_XML1, 'UTF-8');
        $componentId = htmlspecialchars($update['componentId'], ENT_XML1, 'UTF-8');
        $id = htmlspecialchars($update['id'], ENT_XML1, 'UTF-8');
        $status = htmlspecialchars($update['status'], ENT_XML1, 'UTF-8');
        $remediation = htmlspecialchars($update['remediation'], ENT_XML1, 'UTF-8');

        $remediationXml = $remediation === '' ? '<ns1:remediation/>' : "<ns1:remediation>$remediation</ns1:remediation>";

        $replacement = <<<XML
<ns1:PostHealthUpdates><ns1:_this type="HealthUpdateManager">HealthUpdateManager</ns1:_this><ns1:providerId>$providerId</ns1:providerId><ns1:updates><ns1:entity type="HostSystem">$moid</ns1:entity><ns1:healthUpdateInfoId>$componentId</ns1:healthUpdateInfoId><ns1:id>$id</ns1:id><ns1:status>$status</ns1:status>$remediationXml</ns1:updates></ns1:PostHealthUpdates>
XML;

        $request = preg_replace(
            '/<ns1:PostHealthUpdates>.*?<\/ns1:PostHealthUpdates>/s',
            $replacement,
            $request
        );

        return $request;
    }
}

class ManagedObjectReference
{
    public $type;
    public $_;

    public function __construct($type = null, $value = null)
    {
        $this->type = $type;
        $this->_ = $value;
    }

    public static function from($obj)
    {
        if ($obj instanceof self) {
            return $obj;
        }

        if (is_object($obj)) {
            return new self($obj->type ?? null, $obj->_ ?? $obj->value ?? null);
        }

        if (is_array($obj)) {
            return new self($obj['type'] ?? null, $obj['_'] ?? $obj['value'] ?? null);
        }

        return new self(null, $obj);
    }
}

class HealthUpdateInfo
{
    public $id;
    public $componentType;
    public $description;

    public function __construct($id = null, $componentType = null, $description = null)
    {
        $this->id = $id;
        $this->componentType = $componentType;
        $this->description = $description;
    }
}

class HealthUpdate
{
    public $entity;
    public $healthUpdateInfoId;
    public $id;
    public $status;
    public $remediation;

    public function __construct($entity = null, $healthUpdateInfoId = null, $id = null, $status = null, $remediation = null)
    {
        $this->entity = $entity;
        $this->healthUpdateInfoId = $healthUpdateInfoId;
        $this->id = $id;
        $this->status = $status;
        $this->remediation = $remediation;
    }
}

class PropertySpec
{
    public $type;
    public $pathSet;
    public $allPaths;

    public function __construct(array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class ObjectSpec
{
    public $obj;
    public $skip;
    public $selectSet;

    public function __construct(array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class SelectionSpec
{
    public $name;

    public function __construct($name = null)
    {
        $this->name = $name;
    }
}

class TraversalSpec extends SelectionSpec
{
    public $type;
    public $path;
    public $skip;
    public $selectSet;

    public function __construct(array $props = [])
    {
        parent::__construct($props['name'] ?? null);
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class RetrieveOptions
{
    public $maxObjects;

    public function __construct(array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class PropertyFilterSpec
{
    public $propSet;
    public $objectSet;

    public function __construct(array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class VcenterClient
{
    private $url;
    private $username;
    private $password;
    private $verifySsl;
    private $soap;
    private $sessionManager;
    private $serviceContent;
    private $healthUpdateManager;
    private $lastRequest;
    private $lastResponse;
    private $lastActivity;
    private $sessionMaxAge = 1200;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->verifySsl = (bool) $config['verify_ssl'];
    }

    public function connect()
    {
        $this->disconnect();

        $wsdl = $this->url . '/sdk/vimService.wsdl';

        $sslOptions = $this->verifySsl ? [] : [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed'=> true
        ];

        $context = stream_context_create(['ssl' => $sslOptions]);

        try {
            $this->soap = new ProactivehaSoapClient($wsdl, [
                'soap_version'   => SOAP_1_1,
                'trace'          => true,
                'exceptions'     => true,
                'stream_context' => $context,
                'location'       => $this->url . '/sdk',
                'features'       => SOAP_SINGLE_ELEMENT_ARRAYS,
                'cache_wsdl'     => WSDL_CACHE_BOTH,
                'connection_timeout' => 30,
                'classmap' => [
                    'ManagedObjectReference' => ManagedObjectReference::class,
                    'HealthUpdateInfo'       => HealthUpdateInfo::class,
                    'HealthUpdate'           => HealthUpdate::class,
                    'PropertySpec'           => PropertySpec::class,
                    'ObjectSpec'             => ObjectSpec::class,
                    'SelectionSpec'          => SelectionSpec::class,
                    'TraversalSpec'          => TraversalSpec::class,
                    'RetrieveOptions'        => RetrieveOptions::class,
                    'PropertyFilterSpec'     => PropertyFilterSpec::class
                ]
            ]);
        } catch (SoapFault $e) {
            throw new RuntimeException("Failed to load WSDL from $wsdl: " . $e->getMessage());
        }

        try {
            $response = $this->soap->__soapCall('RetrieveServiceContent', [
                ['_this' => new ManagedObjectReference('ServiceInstance', 'ServiceInstance')]
            ]);
        } catch (SoapFault $e) {
            throw new RuntimeException("RetrieveServiceContent failed: " . $e->getMessage() . " | Request: " . $this->sanitizeRequest($this->soap->__getLastRequest()));
        }

        $this->serviceContent = $this->unwrapReturnval($response);

        if (!is_object($this->serviceContent)) {
            throw new RuntimeException('RetrieveServiceContent returned unexpected structure: ' . print_r($response, true));
        }

        $this->sessionManager = $this->serviceContent->sessionManager ?? null;
        $this->healthUpdateManager = $this->serviceContent->healthUpdateManager ?? null;

        if (!$this->sessionManager) {
            throw new RuntimeException('sessionManager not found in ServiceContent');
        }

        try {
            $this->soap->__soapCall('Login', [
                [
                    '_this'    => ManagedObjectReference::from($this->sessionManager),
                    'userName' => $this->username,
                    'password' => $this->password
                ]
            ]);
        } catch (SoapFault $e) {
            throw new RuntimeException("Login failed: " . $e->getMessage() . " | Request: " . $this->sanitizeRequest($this->soap->__getLastRequest()));
        }

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        return $this->serviceContent;
    }

    public function disconnect()
    {
        if ($this->soap && $this->sessionManager) {
            try {
                $this->soap->__soapCall('Logout', [
                    ['_this' => ManagedObjectReference::from($this->sessionManager)]
                ]);
            } catch (\Exception $e) {
                // ignore logout failures
            }
        }

        $this->soap = null;
        $this->serviceContent = null;
        $this->sessionManager = null;
        $this->healthUpdateManager = null;
        $this->lastActivity = null;
    }

    public function ensureConnected()
    {
        if ($this->soap === null || (time() - $this->lastActivity) > $this->sessionMaxAge) {
            $this->connect();
        }
    }

    public function isHealthUpdateManagerAvailable()
    {
        $this->ensureConnected();
        return $this->healthUpdateManager !== null;
    }

    public function registerProvider($name = 'Icinga Proactive HA', $componentType = 'Power', $componentId = 'Power', $description = 'Icinga Proactive HA host health')
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        foreach ($this->queryProviderList() as $id) {
            try {
                if ($this->queryProviderName($id) === $name) {
                    $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
                    $this->lastResponse = $this->soap->__getLastResponse();
                    $this->lastActivity = time();
                    return ProviderId::normalize($id);
                }
            } catch (\Exception $e) {
                // Provider may be stale; continue checking others
            }
        }

        $info = new HealthUpdateInfo($componentId, $componentType, $description);

        try {
            $result = $this->soap->__soapCall('RegisterHealthUpdateProvider', [
                [
                    '_this'            => ManagedObjectReference::from($this->healthUpdateManager),
                    'name'             => $name,
                    'healthUpdateInfo' => [$info]
                ]
            ]);
        } catch (SoapFault $e) {
            if ($this->isAlreadyRegisteredFault($e)) {
                foreach ($this->queryProviderList() as $id) {
                    try {
                        if ($this->queryProviderName($id) === $name) {
                            $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
                            $this->lastResponse = $this->soap->__getLastResponse();
                            $this->lastActivity = time();
                            return ProviderId::normalize($id);
                        }
                    } catch (\Exception $e2) {
                        // ignore
                    }
                }
                throw new RuntimeException("Provider '$name' is already registered but its ID could not be found");
            }
            throw new RuntimeException("RegisterHealthUpdateProvider failed: " . $e->getMessage() . " | Request: " . $this->sanitizeRequest($this->soap->__getLastRequest()));
        }

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        return ProviderId::normalize($this->unwrapReturnval($result));
    }

    public function unregisterProvider($providerId)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $this->soap->__soapCall('UnregisterHealthUpdateProvider', [
            [
                '_this'      => ManagedObjectReference::from($this->healthUpdateManager),
                'providerId' => ProviderId::normalize($providerId)
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();
    }

    public function queryProviderList()
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $result = $this->soap->__soapCall('QueryProviderList', [
            ['_this' => ManagedObjectReference::from($this->healthUpdateManager)]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        $returnval = $this->unwrapReturnval($result);

        if ($returnval === null || $returnval === '') {
            return [];
        }

        $ids = is_array($returnval) ? $returnval : [$returnval];

        return array_map([ProviderId::class, 'normalize'], $ids);
    }

    public function queryProviderName($providerId)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $result = $this->soap->__soapCall('QueryProviderName', [
            [
                '_this' => ManagedObjectReference::from($this->healthUpdateManager),
                'id'    => ProviderId::normalize($providerId)
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        return $this->unwrapReturnval($result);
    }

    public function queryHealthUpdateInfos($providerId)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $result = $this->soap->__soapCall('QueryHealthUpdateInfos', [
            [
                '_this'      => ManagedObjectReference::from($this->healthUpdateManager),
                'providerId' => ProviderId::normalize($providerId)
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        $returnval = $this->unwrapReturnval($result);

        if ($returnval === null || $returnval === '') {
            return [];
        }

        return is_array($returnval) ? $returnval : [$returnval];
    }

    public function queryHealthUpdates($providerId)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $result = $this->soap->__soapCall('QueryHealthUpdates', [
            [
                '_this'      => ManagedObjectReference::from($this->healthUpdateManager),
                'providerId' => ProviderId::normalize($providerId)
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        $returnval = $this->unwrapReturnval($result);

        if ($returnval === null || $returnval === '') {
            return [];
        }

        return is_array($returnval) ? $returnval : [$returnval];
    }

    public function addMonitoredEntities($providerId, array $hostMoIds)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $entities = [];
        foreach ($hostMoIds as $moid) {
            $entities[] = ManagedObjectReference::from(['type' => 'HostSystem', '_' => $moid]);
        }

        $this->soap->__soapCall('AddMonitoredEntities', [
            [
                '_this'      => ManagedObjectReference::from($this->healthUpdateManager),
                'providerId' => ProviderId::normalize($providerId),
                'entities'   => $entities
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();
    }

    public function removeMonitoredEntities($providerId, array $hostMoIds)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $entities = [];
        foreach ($hostMoIds as $moid) {
            $entities[] = ManagedObjectReference::from(['type' => 'HostSystem', '_' => $moid]);
        }

        $this->soap->__soapCall('RemoveMonitoredEntities', [
            [
                '_this'      => ManagedObjectReference::from($this->healthUpdateManager),
                'providerId' => ProviderId::normalize($providerId),
                'entities'   => $entities
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();
    }

    public function hasMonitoredEntity($providerId, $hostMoId)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        $result = $this->soap->__soapCall('HasMonitoredEntity', [
            [
                '_this'      => ManagedObjectReference::from($this->healthUpdateManager),
                'providerId' => ProviderId::normalize($providerId),
                'entity'     => ManagedObjectReference::from(['type' => 'HostSystem', '_' => $hostMoId])
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        return (bool) $this->unwrapReturnval($result);
    }

    public function postHealthUpdates($providerId, $hostMoId, $componentId, $state, $remediation = null)
    {
        $this->ensureConnected();

        if (!$this->healthUpdateManager) {
            throw new RuntimeException('HealthUpdateManager not available');
        }

        if (!in_array($state, ['green', 'yellow', 'red'], true)) {
            throw new InvalidArgumentException("Invalid health state: $state");
        }

        $this->soap->pendingHealthUpdate = [
            'providerId'  => $providerId,
            'moid'        => $hostMoId,
            'componentId' => $componentId,
            'id'          => 'icinga-' . bin2hex(random_bytes(8)),
            'status'      => $state,
            'remediation' => $state === 'green' ? '' : ($remediation ?: "Icinga state is $state")
        ];

        try {
            $this->soap->__soapCall('PostHealthUpdates', [
                [
                    '_this'        => ManagedObjectReference::from($this->healthUpdateManager),
                    'providerId'   => ProviderId::normalize($providerId),
                    'healthUpdate' => []
                ]
            ]);
        } catch (SoapFault $e) {
            if ($this->isSessionFault($e)) {
                $this->disconnect();
                $this->connect();
                return $this->postHealthUpdates($providerId, $hostMoId, $componentId, $state, $remediation);
            }
            throw new RuntimeException("PostHealthUpdates failed: " . $e->getMessage() . " | Request: " . $this->sanitizeRequest($this->soap->__getLastRequest()));
        } finally {
            $this->soap->pendingHealthUpdate = null;
        }

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();
    }

    public function findHostMoid($hostName)
    {
        $hosts = $this->listHosts();
        $hostName = strtolower($hostName);
        $shortName = preg_replace('/\..*$/', '', $hostName);

        foreach ($hosts as $host) {
            $name = strtolower($host['name'] ?? '');
            $dnsName = strtolower($host['dnsName'] ?? '');

            if ($name === $hostName || $dnsName === $hostName) {
                return $host['moid'];
            }
            if ($shortName !== $hostName && ($name === $shortName || $dnsName === $shortName)) {
                return $host['moid'];
            }
        }

        throw new RuntimeException("Host '$hostName' not found in vCenter");
    }

    public function listHosts()
    {
        $this->ensureConnected();

        $containerView = $this->soap->__soapCall('CreateContainerView', [
            [
                '_this'     => ManagedObjectReference::from($this->serviceContent->viewManager),
                'container' => ManagedObjectReference::from($this->serviceContent->rootFolder),
                'type'      => ['HostSystem'],
                'recursive' => true
            ]
        ]);

        $viewRef = $this->unwrapReturnval($containerView);

        $result = $this->soap->__soapCall('RetrievePropertiesEx', [
            [
                '_this'   => ManagedObjectReference::from($this->serviceContent->propertyCollector),
                'specSet' => new PropertyFilterSpec([
                    'propSet' => [
                        new PropertySpec([
                            'type'    => 'HostSystem',
                            'pathSet' => ['name', 'config.network.dnsConfig.hostName']
                        ])
                    ],
                    'objectSet' => [
                        new ObjectSpec([
                            'obj'       => $viewRef,
                            'skip'      => true,
                            'selectSet' => [
                                new TraversalSpec([
                                    'name' => 'ContainerViewTraversalSpec',
                                    'type' => 'ContainerView',
                                    'path' => 'view',
                                    'skip' => false
                                ])
                            ]
                        ])
                    ]
                ]),
                'options' => new RetrieveOptions(['maxObjects' => 1000])
            ]
        ]);

        $this->lastActivity = time();

        $hosts = $this->parseHostProperties($result);

        $this->soap->__soapCall('DestroyView', [
            ['_this' => ManagedObjectReference::from($viewRef)]
        ]);

        return $hosts;
    }

    public function listClusterHosts($clusterMoId)
    {
        $this->ensureConnected();

        $clusterRef = ManagedObjectReference::from(['type' => 'ClusterComputeResource', '_' => $clusterMoId]);

        $containerView = $this->soap->__soapCall('CreateContainerView', [
            [
                '_this'     => ManagedObjectReference::from($this->serviceContent->viewManager),
                'container' => $clusterRef,
                'type'      => ['HostSystem'],
                'recursive' => true
            ]
        ]);

        $viewRef = $this->unwrapReturnval($containerView);

        $result = $this->soap->__soapCall('RetrievePropertiesEx', [
            [
                '_this'   => ManagedObjectReference::from($this->serviceContent->propertyCollector),
                'specSet' => new PropertyFilterSpec([
                    'propSet' => [
                        new PropertySpec([
                            'type'    => 'HostSystem',
                            'pathSet' => ['name', 'config.network.dnsConfig.hostName']
                        ])
                    ],
                    'objectSet' => [
                        new ObjectSpec([
                            'obj'       => $viewRef,
                            'skip'      => true,
                            'selectSet' => [
                                new TraversalSpec([
                                    'name' => 'ContainerViewTraversalSpec',
                                    'type' => 'ContainerView',
                                    'path' => 'view',
                                    'skip' => false
                                ])
                            ]
                        ])
                    ]
                ]),
                'options' => new RetrieveOptions(['maxObjects' => 1000])
            ]
        ]);

        $this->lastActivity = time();

        $hosts = $this->parseHostProperties($result);

        $this->soap->__soapCall('DestroyView', [
            ['_this' => ManagedObjectReference::from($viewRef)]
        ]);

        return $hosts;
    }

    public function listClusters()
    {
        $this->ensureConnected();

        $containerView = $this->soap->__soapCall('CreateContainerView', [
            [
                '_this'     => ManagedObjectReference::from($this->serviceContent->viewManager),
                'container' => ManagedObjectReference::from($this->serviceContent->rootFolder),
                'type'      => ['ClusterComputeResource'],
                'recursive' => true
            ]
        ]);

        $viewRef = $this->unwrapReturnval($containerView);

        $result = $this->soap->__soapCall('RetrievePropertiesEx', [
            [
                '_this'   => ManagedObjectReference::from($this->serviceContent->propertyCollector),
                'specSet' => new PropertyFilterSpec([
                    'propSet' => [
                        new PropertySpec([
                            'type'    => 'ClusterComputeResource',
                            'pathSet' => ['name']
                        ])
                    ],
                    'objectSet' => [
                        new ObjectSpec([
                            'obj'       => $viewRef,
                            'skip'      => true,
                            'selectSet' => [
                                new TraversalSpec([
                                    'name' => 'ContainerViewTraversalSpec',
                                    'type' => 'ContainerView',
                                    'path' => 'view',
                                    'skip' => false
                                ])
                            ]
                        ])
                    ]
                ]),
                'options' => new RetrieveOptions(['maxObjects' => 1000])
            ]
        ]);

        $this->lastActivity = time();

        $clusters = $this->parseClusterProperties($result);

        $this->soap->__soapCall('DestroyView', [
            ['_this' => ManagedObjectReference::from($viewRef)]
        ]);

        return $clusters;
    }

    public function enableClusterProactiveHa($clusterMoId, $providerId, $mode, $moderate, $severe)
    {
        $this->ensureConnected();

        $clusterRef = ManagedObjectReference::from(['type' => 'ClusterComputeResource', '_' => $clusterMoId]);
        $config = $this->getClusterConfig($clusterRef);

        $providers = $config['providers'] ?? [];
        $normalizedProvider = ProviderId::normalize($providerId);

        $found = false;
        foreach ($providers as $existing) {
            if (ProviderId::normalize($existing) === $normalizedProvider) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $providers[] = $normalizedProvider;
        }

        $spec = $this->buildClusterConfigSpec(true, $mode, $moderate, $severe, $providers);

        $task = $this->soap->__soapCall('ReconfigureComputeResource_Task', [
            [
                '_this'  => $clusterRef,
                'spec'   => $spec,
                'modify' => true
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        $this->waitForTask(ManagedObjectReference::from($this->unwrapReturnval($task)));
    }

    public function disableClusterProactiveHa($clusterMoId, $providerId)
    {
        $this->ensureConnected();

        $clusterRef = ManagedObjectReference::from(['type' => 'ClusterComputeResource', '_' => $clusterMoId]);
        $config = $this->getClusterConfig($clusterRef);

        $providers = [];
        $normalizedProvider = ProviderId::normalize($providerId);

        foreach ($config['providers'] ?? [] as $existing) {
            if (ProviderId::normalize($existing) !== $normalizedProvider) {
                $providers[] = ProviderId::normalize($existing);
            }
        }

        $enabled = !empty($providers);
        $mode = $config['mode'] ?? 'Manual';
        $moderate = $config['moderateRemediation'] ?? 'QuarantineMode';
        $severe = $config['severeRemediation'] ?? 'QuarantineMode';

        $spec = $this->buildClusterConfigSpec($enabled, $mode, $moderate, $severe, $providers);

        $task = $this->soap->__soapCall('ReconfigureComputeResource_Task', [
            [
                '_this'  => $clusterRef,
                'spec'   => $spec,
                'modify' => true
            ]
        ]);

        $this->lastRequest = $this->sanitizeRequest($this->soap->__getLastRequest());
        $this->lastResponse = $this->soap->__getLastResponse();
        $this->lastActivity = time();

        $this->waitForTask(ManagedObjectReference::from($this->unwrapReturnval($task)));
    }

    public function getClusterProactiveHaConfig($clusterMoId)
    {
        $this->ensureConnected();

        $clusterRef = ManagedObjectReference::from(['type' => 'ClusterComputeResource', '_' => $clusterMoId]);
        return $this->getClusterConfig($clusterRef);
    }

    public function getLastRequest()
    {
        return $this->lastRequest ?: ($this->soap ? $this->sanitizeRequest($this->soap->__getLastRequest()) : null);
    }

    public function getLastResponse()
    {
        return $this->lastResponse ?: ($this->soap ? $this->soap->__getLastResponse() : null);
    }

    private function sanitizeRequest($xml)
    {
        if ($xml === null) {
            return null;
        }
        return preg_replace('/(<(?:[a-zA-Z0-9_-]+:)?password>).*?(<\/(?:[a-zA-Z0-9_-]+:)?password>)/s', '$1***$2', $xml);
    }

    private function getClusterConfig($clusterRef)
    {
        $result = $this->soap->__soapCall('RetrievePropertiesEx', [
            [
                '_this'   => ManagedObjectReference::from($this->serviceContent->propertyCollector),
                'specSet' => new PropertyFilterSpec([
                    'propSet' => [
                        new PropertySpec([
                            'type'    => 'ClusterComputeResource',
                            'pathSet' => ['configurationEx']
                        ])
                    ],
                    'objectSet' => [
                        new ObjectSpec([
                            'obj' => $clusterRef
                        ])
                    ]
                ]),
                'options' => new RetrieveOptions(['maxObjects' => 1])
            ]
        ]);

        $this->lastActivity = time();

        $returnval = $this->unwrapReturnval($result);
        if (!is_object($returnval) || !isset($returnval->objects)) {
            return null;
        }

        $objects = is_array($returnval->objects) ? $returnval->objects : [$returnval->objects];
        foreach ($objects as $obj) {
            if (!isset($obj->propSet)) {
                continue;
            }
            $props = is_array($obj->propSet) ? $obj->propSet : [$obj->propSet];
            foreach ($props as $prop) {
                if ($prop->name === 'configurationEx') {
                    return $this->parseInfraUpdateHaConfig($prop->val);
                }
            }
        }

        return null;
    }

    private function parseInfraUpdateHaConfig($configEx)
    {
        if (!is_object($configEx)) {
            return null;
        }

        $infra = $configEx->infraUpdateHaConfig ?? null;
        if (!$infra) {
            return null;
        }

        $providers = [];
        if (isset($infra->providers)) {
            $raw = is_array($infra->providers) ? $infra->providers : [$infra->providers];
            foreach ($raw as $p) {
                $providers[] = ProviderId::normalize($this->extractProviderId($p));
            }
        }

        return [
            'enabled'             => !empty($infra->enabled),
            'mode'                => $infra->behavior ?? 'Manual',
            'moderateRemediation' => $infra->moderateRemediation ?? 'QuarantineMode',
            'severeRemediation'   => $infra->severeRemediation ?? 'QuarantineMode',
            'providers'           => $providers
        ];
    }

    private function extractProviderId($p)
    {
        if ($p instanceof ManagedObjectReference) {
            return $p->_;
        }
        if (is_object($p)) {
            return $p->_ ?? $p->value ?? $p->id ?? null;
        }
        if (is_array($p)) {
            return $p['_'] ?? $p['value'] ?? $p['id'] ?? null;
        }
        return $p;
    }

    private function buildClusterConfigSpec($enabled, $mode, $moderate, $severe, array $providers)
    {
        $providerIds = [];
        foreach ($providers as $p) {
            $providerIds[] = ProviderId::normalize($p);
        }

        return [
            'infraUpdateHaConfig' => [
                'enabled'             => (bool) $enabled,
                'behavior'            => $mode,
                'moderateRemediation' => $moderate,
                'severeRemediation'   => $severe,
                'providers'           => $providerIds
            ]
        ];
    }

    private function waitForTask($taskRef, $timeout = 300)
    {
        $start = time();

        while (time() - $start < $timeout) {
            $result = $this->soap->__soapCall('RetrievePropertiesEx', [
                [
                    '_this'   => ManagedObjectReference::from($this->serviceContent->propertyCollector),
                    'specSet' => new PropertyFilterSpec([
                        'propSet' => [
                            new PropertySpec([
                                'type'    => 'Task',
                                'pathSet' => ['info.state', 'info.error']
                            ])
                        ],
                        'objectSet' => [
                            new ObjectSpec([
                                'obj' => ManagedObjectReference::from($taskRef)
                            ])
                        ]
                    ]),
                    'options' => new RetrieveOptions(['maxObjects' => 1])
                ]
            ]);

            $this->lastActivity = time();

            $returnval = $this->unwrapReturnval($result);
            if (!is_object($returnval) || !isset($returnval->objects)) {
                sleep(2);
                continue;
            }

            $objects = is_array($returnval->objects) ? $returnval->objects : [$returnval->objects];
            foreach ($objects as $obj) {
                if (!isset($obj->propSet)) {
                    continue;
                }
                $props = is_array($obj->propSet) ? $obj->propSet : [$obj->propSet];
                $state = null;
                $error = null;
                foreach ($props as $prop) {
                    if ($prop->name === 'info.state') {
                        $state = is_object($prop->val) ? $prop->val->_ : $prop->val;
                    } elseif ($prop->name === 'info.error') {
                        $error = $prop->val;
                    }
                }

                if ($state === 'success') {
                    return;
                }
                if ($state === 'error') {
                    $message = 'Task failed';
                    if (is_object($error) && isset($error->localizedMessage)) {
                        $message = $error->localizedMessage;
                    } elseif (is_object($error) && isset($error->fault)) {
                        $message = $error->fault->faultstring ?? $message;
                    }
                    throw new RuntimeException("Cluster reconfiguration failed: $message");
                }
            }

            sleep(2);
        }

        throw new RuntimeException('Cluster reconfiguration task timed out');
    }

    private function isSessionFault(SoapFault $e)
    {
        $code = $e->faultcode ?? '';
        $message = $e->getMessage();
        return stripos($code, 'NotAuthenticated') !== false
            || stripos($message, 'not authenticated') !== false
            || stripos($message, 'session') !== false;
    }

    private function isAlreadyRegisteredFault(SoapFault $e)
    {
        $message = $e->getMessage();
        $code = $e->faultcode ?? '';

        return stripos($message, 'already registered') !== false
            || stripos($message, 'already exists') !== false
            || stripos($message, 'duplicate') !== false
            || (stripos($message, 'name') !== false && stripos($message, 'exists') !== false)
            || stripos($code, 'AlreadyExists') !== false
            || (stripos($code, 'InvalidArgument') !== false && stripos($message, 'registered') !== false)
            || (stripos($code, 'InvalidArgument') !== false && stripos($message, 'providerId') !== false);
    }

    private function unwrapReturnval($response)
    {
        if (is_object($response) && isset($response->returnval)) {
            return $response->returnval;
        }
        return $response;
    }

    private function parseHostProperties($result)
    {
        $hosts = [];

        while (true) {
            $returnval = $this->unwrapReturnval($result);

            if (!is_object($returnval) || !isset($returnval->objects)) {
                break;
            }

            $objects = is_array($returnval->objects) ? $returnval->objects : [$returnval->objects];

            foreach ($objects as $obj) {
                $moid = null;
                $name = null;
                $dnsName = null;

                if (isset($obj->obj)) {
                    $moid = $this->extractMoid($obj->obj);
                }

                if (isset($obj->propSet)) {
                    $props = is_array($obj->propSet) ? $obj->propSet : [$obj->propSet];
                    foreach ($props as $prop) {
                        if ($prop->name === 'name') {
                            $name = is_object($prop->val) ? $prop->val->_ : $prop->val;
                        } elseif ($prop->name === 'config.network.dnsConfig.hostName') {
                            $dnsName = is_object($prop->val) ? $prop->val->_ : $prop->val;
                        }
                    }
                }

                $hosts[] = ['moid' => $moid, 'name' => $name, 'dnsName' => $dnsName];
            }

            if (!empty($returnval->token)) {
                $result = $this->soap->__soapCall('ContinueRetrievePropertiesEx', [
                    [
                        '_this' => ManagedObjectReference::from($this->serviceContent->propertyCollector),
                        'token' => $returnval->token
                    ]
                ]);
            } else {
                break;
            }
        }

        return $hosts;
    }

    private function parseClusterProperties($result)
    {
        $clusters = [];

        while (true) {
            $returnval = $this->unwrapReturnval($result);

            if (!is_object($returnval) || !isset($returnval->objects)) {
                break;
            }

            $objects = is_array($returnval->objects) ? $returnval->objects : [$returnval->objects];

            foreach ($objects as $obj) {
                $moid = null;
                $name = null;

                if (isset($obj->obj)) {
                    $moid = $this->extractMoid($obj->obj);
                }

                if (isset($obj->propSet)) {
                    $props = is_array($obj->propSet) ? $obj->propSet : [$obj->propSet];
                    foreach ($props as $prop) {
                        if ($prop->name === 'name') {
                            $name = is_object($prop->val) ? $prop->val->_ : $prop->val;
                        }
                    }
                }

                $clusters[] = ['moid' => $moid, 'name' => $name];
            }

            if (!empty($returnval->token)) {
                $result = $this->soap->__soapCall('ContinueRetrievePropertiesEx', [
                    [
                        '_this' => ManagedObjectReference::from($this->serviceContent->propertyCollector),
                        'token' => $returnval->token
                    ]
                ]);
            } else {
                break;
            }
        }

        return $clusters;
    }

    private function extractMoid($mor)
    {
        if ($mor instanceof ManagedObjectReference) {
            return $mor->_;
        }
        if (is_object($mor)) {
            return $mor->_ ?? $mor->value ?? null;
        }
        return $mor;
    }
}
