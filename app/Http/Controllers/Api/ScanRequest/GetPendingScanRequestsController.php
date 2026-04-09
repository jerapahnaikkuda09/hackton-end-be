<?php

namespace App\Http\Controllers\Api\ScanRequest;

use App\Http\Controllers\Controller;
use App\Models\ScanRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetPendingScanRequestsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $pending = ScanRequest::where('owner_user_id', auth()->id())
            ->where('status', 'pending')
            ->latest()
            ->get(['id', 'requester_user_id', 'repo_url', 'status', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => $pending,
        ]);
    }
}
