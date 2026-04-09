<?php

namespace App\Http\Controllers\Api\PrComments;

use App\Http\Controllers\Controller;
use App\Models\PrComment;
use Illuminate\Http\JsonResponse;

class GetPrCommentsController extends Controller
{
    public function index(): JsonResponse
    {
        $comments = PrComment::whereHas('scan', fn($q) => $q->where('user_id', auth()->id()))
            ->with('scan:id,repository,branch,max_severity')
            ->latest()
            ->paginate(20);

        return response()->json($comments);
    }

    public function show(string $id): JsonResponse
    {
        $comment = PrComment::whereHas('scan', fn($q) => $q->where('user_id', auth()->id()))
            ->with('scan')
            ->findOrFail($id);

        return response()->json($comment);
    }
}

