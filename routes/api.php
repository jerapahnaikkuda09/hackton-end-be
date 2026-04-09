<?php
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Scan\StoreLocalScanController;
use App\Http\Controllers\Api\Scan\StoreGithubPrScanController;
use App\Http\Controllers\Api\Scan\GetScansController;
use App\Http\Controllers\Api\Scan\DeleteScanController;
use App\Http\Controllers\Api\ScanRequest\RequestRepoScanController;
use App\Http\Controllers\Api\ScanRequest\GetPendingScanRequestsController;
use App\Http\Controllers\Api\ScanRequest\GetScanRequestController;
use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Dashboard\StatsController;
use App\Http\Controllers\Api\PrComment\GetPrCommentsController;
use App\Http\Controllers\Api\LLM\AskIssueController;

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

    // Scan Requests (sinkronisasi repo user lain via URL)
    Route::post('/scan-requests',          RequestRepoScanController::class);
    Route::get('/scan-requests/pending',   GetPendingScanRequestsController::class);
    Route::get('/scan-requests/{id}',      GetScanRequestController::class);

    // User & Dashboard
    Route::get('/auth/me',          MeController::class);
    Route::get('/dashboard/stats',  StatsController::class);
    Route::get('/pr-comments',      [GetPrCommentsController::class, 'index']);
    Route::get('/pr-comments/{id}', [GetPrCommentsController::class, 'show']);

    // LLM / AI
    Route::post('/llm/ask', AskIssueController::class);
});

GEMINI_API_KEY="AIzaSyBpZ0bSZfUHk82XSSKHikF53T3kI-UX3bs"
