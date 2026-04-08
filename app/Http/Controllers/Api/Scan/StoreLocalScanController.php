<?php

namespace App\Http\Controllers\Api\Scan;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StoreLocalScanController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'repository'  => 'nullable|string|max:255',
            'branch'      => 'nullable|string|max:255',
            'commit_hash' => 'nullable|string|max:255',
            'issues'      => 'required|array',
            'issues.*.file'     => 'required|string',
            'issues.*.line'     => 'nullable|integer',
            'issues.*.severity' => 'required|in:info,warning,critical',
            'issues.*.type'     => 'required|string',
            'issues.*.message'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $issues        = $request->issues;
        $totalCritical = collect($issues)->where('severity', 'critical')->count();
        $totalWarning  = collect($issues)->where('severity', 'warning')->count();
        $totalInfo     = collect($issues)->where('severity', 'info')->count();

        $maxSeverity = 'none';
        if ($totalCritical > 0) {
            $maxSeverity = 'critical';
        } elseif ($totalWarning > 0) {
            $maxSeverity = 'warning';
        } elseif ($totalInfo > 0) {
            $maxSeverity = 'info';
        }

        $scan = Scan::create([
            'repository'     => $request->repository,
            'branch'         => $request->branch,
            'commit_hash'    => $request->commit_hash,
            'source'         => 'local',
            'issues'         => $issues,
            'total_critical' => $totalCritical,
            'total_warning'  => $totalWarning,
            'total_info'     => $totalInfo,
            'max_severity'   => $maxSeverity,
            'blocked'        => $totalCritical > 0,
        ]);

        return response()->json([
            'success'      => true,
            'scan_id'      => $scan->id,
            'max_severity' => $maxSeverity,
            'blocked'      => $scan->blocked,
            'summary'      => [
                'critical' => $totalCritical,
                'warning'  => $totalWarning,
                'info'     => $totalInfo,
            ],
        ], 201);
    }
}
