<?php

namespace App\Http\Controllers\Api\ScanRequest;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use App\Models\ScanRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RequestRepoScanController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'repo_url' => 'required|url|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Cari pemilik repo dari scan terakhir dengan URL ini
        $lastScan = Scan::where('repo_url', $request->repo_url)
            ->whereNotNull('user_id')
            ->latest()
            ->first();

        if (!$lastScan) {
            return response()->json([
                'success' => false,
                'message' => 'Repository ini tidak terdaftar di sistem kami atau belum memiliki pemilik.',
            ], 403);
        }

        $scanRequest = ScanRequest::create([
            'requester_user_id' => auth()->id(),
            'owner_user_id'     => $lastScan->user_id,
            'repo_url'          => $request->repo_url,
            'status'            => 'pending',
        ]);

        return response()->json([
            'success'          => true,
            'scan_request_id'  => $scanRequest->id,
            'repo_url'         => $scanRequest->repo_url,
            'status'           => $scanRequest->status,
            'owner_found'      => true,
        ], 201);
    }
}
