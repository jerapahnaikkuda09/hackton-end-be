<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\StoreLocalScanController;
use App\Http\Controllers\Api\LLM\ExplainIssueController;

Route::post('/v1/scans', [StoreLocalScanController::class, 'store']);
Route::post('/v1/explain', [ExplainIssueController::class, 'explain']);
