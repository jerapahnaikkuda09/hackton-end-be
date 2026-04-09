<?php

namespace App\Http\Controllers\Api\ScanRequest;

use App\Http\Controllers\Controller;
use App\Models\ScanRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetScanRequestController extends Controller
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $scanRequest = ScanRequest::where('id', $id)
            ->where('requester_user_id', auth()->id())
            ->first();

        if (!$scanRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Scan request tidak ditemukan.',
            ], 404);
        }

        $result = [
            'success'         => true,
            'scan_request_id' => $scanRequest->id,
            'repo_url'        => $scanRequest->repo_url,
            'status'          => $scanRequest->status,
            'created_at'      => $scanRequest->created_at,
        ];

        if ($scanRequest->status === 'fulfilled' && $scanRequest->fulfilledScan) {
            $scan = $scanRequest->fulfilledScan;
            $result['scan'] = [
                'id'             => $scan->id,
                'repository'     => $scan->repository,
                'branch'         => $scan->branch,
                'commit_hash'    => $scan->commit_hash,
                'max_severity'   => $scan->max_severity,
                'total_critical' => $scan->total_critical,
                'total_warning'  => $scan->total_warning,
                'total_info'     => $scan->total_info,
                'blocked'        => $scan->blocked,
                'issues'         => $scan->issues,
                'scanned_at'     => $scan->created_at,
            ];
        }

        return response()->json($result);
    }
}
