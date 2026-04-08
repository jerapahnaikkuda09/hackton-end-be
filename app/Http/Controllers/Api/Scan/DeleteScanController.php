<?php

namespace App\Http\Controllers\Api\Scan;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;

class DeleteScanController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $scan = Scan::findOrFail($id);
        $scan->delete();
        return response()->json(['success' => true]);
    }
}
