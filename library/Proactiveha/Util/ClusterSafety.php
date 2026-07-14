<?php

namespace Icinga\Module\Proactiveha\Util;

use Icinga\Module\Proactiveha\Model\Cluster;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

class ClusterSafety
{
    /** @var Connection */
    private $db;

    /** @var EventLogger */
    private $logger;

    public function __construct(Connection $db, EventLogger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Determine whether pushing red to the given mapping is allowed.
     *
     * Returns ['allowed' => true] or ['allowed' => false, 'reason' => '...'].
     */
    public function canPushRed(Mapping $mapping): array
    {
        if (!$mapping->cluster_id) {
            $message = sprintf(
                'Cannot enforce cluster safety for %s: cluster membership unknown',
                $mapping->vsphere_host_name
            );
            $this->log('warning', 'cluster_safety_unknown', $message, [
                'mapping_id' => $mapping->id,
                'host' => $mapping->vsphere_host_name
            ]);
            return ['allowed' => true];
        }

        $cluster = Cluster::on($this->db)
            ->filter(Filter::equal('id', $mapping->cluster_id))
            ->first();

        if (!$cluster) {
            $message = sprintf(
                'Cluster %s for mapping %d no longer exists; allowing red push',
                $mapping->cluster_id,
                $mapping->id
            );
            $this->log('warning', 'cluster_safety_missing_cluster', $message, [
                'mapping_id' => $mapping->id,
                'cluster_id' => $mapping->cluster_id
            ]);
            return ['allowed' => true];
        }

        $threshold = (int) $cluster->min_non_red_hosts;

        if ($threshold <= 0) {
            return ['allowed' => true];
        }

        $mappedHostIds = $this->getMappedHostIdsInCluster($mapping->cluster_id);
        $totalMapped = count($mappedHostIds);

        if ($totalMapped === 0) {
            return ['allowed' => true];
        }

        $effectivelyRed = $this->countEffectivelyRedHosts($mappedHostIds, $mapping->id);

        $maxAllowedRed = max(0, $totalMapped - $threshold);

        if ($effectivelyRed > $maxAllowedRed) {
            $reason = sprintf(
                'Pushing red to %s would leave only %d non-red host(s) in cluster "%s" (threshold: %d, mapped hosts: %d, effectively red: %d)',
                $mapping->vsphere_host_name,
                $totalMapped - $effectivelyRed,
                $cluster->name,
                $threshold,
                $totalMapped,
                $effectivelyRed
            );

            $this->log('warning', 'push_blocked_by_cluster_safety', $reason, [
                'mapping_id' => $mapping->id,
                'cluster_id' => $cluster->id,
                'cluster_name' => $cluster->name,
                'threshold' => $threshold,
                'total_mapped' => $totalMapped,
                'effectively_red' => $effectivelyRed
            ]);

            return ['allowed' => false, 'reason' => $reason];
        }

        return ['allowed' => true];
    }

    /**
     * Get IDs of enabled mappings in the cluster that have a host MOID.
     */
    private function getMappedHostIdsInCluster($clusterId): array
    {
        $query = Mapping::on($this->db)
            ->filter(Filter::all(
                Filter::equal('cluster_id', $clusterId),
                Filter::equal('enabled', 1)
            ))
            ->columns(['id']);

        $ids = [];
        foreach ($query->execute() as $mapping) {
            $ids[] = (int) $mapping->id;
        }

        return $ids;
    }

    /**
     * Count how many mapped hosts in the cluster are effectively red.
     *
     * A host is effectively red if:
     * - desired_state is red (2), regardless of push_status, OR
     * - it has no state record yet, OR
     * - its last push is blocked by cluster safety
     */
    private function countEffectivelyRedHosts(array $mappingIds, $currentMappingId): int
    {
        if (empty($mappingIds)) {
            return 0;
        }

        $states = State::on($this->db)
            ->filter(Filter::equal('mapping_id', $mappingIds))
            ->execute();

        $stateByMapping = [];
        foreach ($states as $state) {
            $stateByMapping[(int) $state->mapping_id] = $state;
        }

        $count = 0;
        foreach ($mappingIds as $mappingId) {
            if ($mappingId === (int) $currentMappingId) {
                // The current host is about to be pushed red, so count it as red
                $count++;
                continue;
            }

            if (!isset($stateByMapping[$mappingId])) {
                $count++;
                continue;
            }

            $state = $stateByMapping[$mappingId];

            if ((int) $state->desired_state === 2) {
                $count++;
                continue;
            }

            if ($state->push_status === 'blocked') {
                $count++;
                continue;
            }
        }

        return $count;
    }

    private function log($level, $eventType, $message, array $context = [])
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $eventType, $message, $context);
        }
    }
}
