<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $userId = auth()->id();

        $scans = Scan::where('user_id', $userId);

        $totalVulnerabilities = (clone $scans)->sum('total_critical')
            + (clone $scans)->sum('total_warning')
            + (clone $scans)->sum('total_info');

        $criticalExposure = (clone $scans)->sum('total_critical');

        $totalScans = (clone $scans)->count();

        $severityDistribution = [
            'critical' => (clone $scans)->sum('total_critical'),
            'warning'  => (clone $scans)->sum('total_warning'),
            'info'     => (clone $scans)->sum('total_info'),
        ];

        // Repositori dengan isu terbanyak
        $highestRiskRepos = (clone $scans)
            ->selectRaw('repository, SUM(total_critical) as critical_count, MAX(created_at) as last_activity')
            ->groupBy('repository')
            ->orderByDesc('critical_count')
            ->limit(5)
            ->get()
            ->map(fn($s) => [
                'repository'    => $s->repository,
                'critical'      => $s->critical_count,
                'last_activity' => $s->last_activity,
            ]);

        // Scan terakhir
        $lastScan = (clone $scans)->latest()->first();

        return response()->json([
            'success'               => true,
            'total_vulnerabilities' => $totalVulnerabilities,
            'critical_exposure'     => $criticalExposure,
            'total_scans'           => $totalScans,
            'severity_distribution' => $severityDistribution,
            'highest_risk_repos'    => $highestRiskRepos,
            'last_scan'             => $lastScan ? [
                'created_at'    => $lastScan->created_at,
                'repository'    => $lastScan->repository,
                'max_severity'  => $lastScan->max_severity,
            ] : null,
        ]);
    }
}