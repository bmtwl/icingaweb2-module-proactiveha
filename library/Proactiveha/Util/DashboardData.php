<?php

namespace Icinga\Module\Proactiveha\Util;

use Icinga\Module\Proactiveha\Model\Cluster;
use Icinga\Module\Proactiveha\Model\Log;
use Icinga\Module\Proactiveha\Model\Mapping;
use Icinga\Module\Proactiveha\Model\State;
use Icinga\Module\Proactiveha\Model\SyncRun;
use Icinga\Module\Proactiveha\Model\Vcenter;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

class DashboardData
{
    /** @var Connection */
    private $db;

    /** @var array */
    private $metrics;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function getMetrics()
    {
        if ($this->metrics === null) {
            $this->metrics = [
                'vcenter'  => $this->collectVcenterMetrics(),
                'cluster'  => $this->collectClusterMetrics(),
                'mapping'  => $this->collectMappingMetrics(),
                'state'    => $this->collectStateMetrics(),
                'sync'     => $this->collectSyncMetrics(),
                'logs'     => $this->collectLogMetrics()
            ];
        }

        return $this->metrics;
    }

    private function collectVcenterMetrics()
    {
        $query = Vcenter::on($this->db);
        $all = iterator_to_array($query->execute());

        $total = count($all);
        $enabled = 0;
        $disabled = 0;
        $providerRegistered = 0;
        $providerMissing = 0;

        foreach ($all as $vcenter) {
            if ($vcenter->enabled) {
                $enabled++;
            } else {
                $disabled++;
            }

            if (!empty($vcenter->provider_key) && $vcenter->provider_registered) {
                $providerRegistered++;
            } else {
                $providerMissing++;
            }
        }

        return [
            'total'               => $total,
            'enabled'             => $enabled,
            'disabled'            => $disabled,
            'provider_registered' => $providerRegistered,
            'provider_missing'    => $providerMissing
        ];
    }

    private function collectClusterMetrics()
    {
        $query = Cluster::on($this->db)->with('vcenter');
        $all = iterator_to_array($query->execute());

        $total = count($all);
        $enabled = 0;
        $disabled = 0;
        $providerEnabled = 0;
        $providerDisabled = 0;

        foreach ($all as $cluster) {
            if ($cluster->enabled) {
                $enabled++;
            } else {
                $disabled++;
            }

            if ($cluster->provider_enabled) {
                $providerEnabled++;
            } else {
                $providerDisabled++;
            }
        }

        return [
            'total'             => $total,
            'enabled'           => $enabled,
            'disabled'          => $disabled,
            'provider_enabled'  => $providerEnabled,
            'provider_disabled' => $providerDisabled
        ];
    }

    private function collectMappingMetrics()
    {
        $query = Mapping::on($this->db)->with('vcenter');
        $all = iterator_to_array($query->execute());

        $total = count($all);
        $enabled = 0;
        $disabled = 0;
        $withMoid = 0;
        $withoutMoid = 0;

        foreach ($all as $mapping) {
            if ($mapping->enabled) {
                $enabled++;
            } else {
                $disabled++;
            }

            if (!empty($mapping->vsphere_host_moid)) {
                $withMoid++;
            } else {
                $withoutMoid++;
            }
        }

        return [
            'total'         => $total,
            'enabled'       => $enabled,
            'disabled'      => $disabled,
            'with_moid'     => $withMoid,
            'without_moid'  => $withoutMoid
        ];
    }

    private function collectStateMetrics()
    {
        $query = State::on($this->db)->with('mapping');
        $all = iterator_to_array($query->execute());

        $green = 0;
        $yellow = 0;
        $red = 0;
        $pending = 0;
        $inProgress = 0;
        $synced = 0;
        $failed = 0;

        foreach ($all as $state) {
            switch ($state->vsphere_state) {
                case 'green':  $green++;  break;
                case 'yellow': $yellow++; break;
                case 'red':    $red++;    break;
            }

            switch ($state->push_status) {
                case 'pending':     $pending++;     break;
                case 'in_progress': $inProgress++;  break;
                case 'synced':      $synced++;      break;
                case 'failed':      $failed++;      break;
            }
        }

        return [
            'green'        => $green,
            'yellow'       => $yellow,
            'red'          => $red,
            'pending'      => $pending,
            'in_progress'  => $inProgress,
            'synced'       => $synced,
            'failed'       => $failed,
            'total'        => count($all)
        ];
    }

    private function collectSyncMetrics()
    {
        $lastRun = SyncRun::on($this->db)
            ->orderBy('started_at', SORT_DESC)
            ->limit(1)
            ->first();

        $lastSuccess = SyncRun::on($this->db)
            ->filter(Filter::equal('status', 'success'))
            ->orderBy('started_at', SORT_DESC)
            ->limit(1)
            ->first();

        $lastFailure = SyncRun::on($this->db)
            ->filter(Filter::equal('status', 'failed'))
            ->orderBy('started_at', SORT_DESC)
            ->limit(1)
            ->first();

        return [
            'last_run'     => $lastRun,
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure
        ];
    }

    private function collectLogMetrics()
    {
        $recentErrors = iterator_to_array(
            Log::on($this->db)
                ->with(['mapping', 'vcenter'])
                ->filter(Filter::equal('level', 'error'))
                ->orderBy('timestamp', SORT_DESC)
                ->limit(5)
                ->execute()
        );

        $recentWarnings = iterator_to_array(
            Log::on($this->db)
                ->with(['mapping', 'vcenter'])
                ->filter(Filter::equal('level', 'warning'))
                ->orderBy('timestamp', SORT_DESC)
                ->limit(5)
                ->execute()
        );

        return [
            'recent_errors'   => $recentErrors,
            'recent_warnings' => $recentWarnings
        ];
    }
}
