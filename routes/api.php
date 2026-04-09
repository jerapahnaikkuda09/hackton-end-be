<?php

use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Scan\StoreLocalScanController;
use App\Http\Controllers\Api\Scan\StoreGithubPrScanController;
use App\Http\Controllers\Api\Scan\GetScansController;
use App\Http\Controllers\Api\Scan\DeleteScanController;
use App\Http\Controllers\Api\LLM\ExplainIssueController;
use App\Http\Controllers\Api\LLM\GenerateFixController;
use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────
// Route Publik (tidak butuh API Token)
// ─────────────────────────────────────────────
Route::prefix('v1/auth')->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login',    LoginController::class);
});

// ─────────────────────────────────────────────
// Route Terproteksi (butuh X-API-Token header)
// ─────────────────────────────────────────────
Route::prefix('v1')->middleware(ApiTokenAuth::class)->group(function () {
    // Scan
    Route::post('/scans',         StoreLocalScanController::class);
    Route::post('/scans/github',  StoreGithubPrScanController::class);
    Route::get('/scans',          [GetScansController::class, 'index']);
    Route::get('/scans/{id}',     [GetScansController::class, 'show']);
    Route::delete('/scans/{id}',  DeleteScanController::class);

    // LLM / AI
    Route::post('/llm/explain',      ExplainIssueController::class);
    Route::post('/llm/generate-fix', GenerateFixController::class);
});