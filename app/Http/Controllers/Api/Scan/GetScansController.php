<?php

namespace App\Http\Controllers\Api\Scan;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetScansController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Scan::query()->where('user_id', auth()->id())->latest();

        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        if ($request->has('severity')) {
            $query->where('max_severity', $request->severity);
        }

        $scans = $query->paginate(20);

        return response()->json($scans);
    }

    public function show(string $id): JsonResponse
    {
        $scan = Scan::with('prComments')
            ->where('user_id', auth()->id())
            ->findOrFail($id);

        return response()->json($scan);
    }
}
