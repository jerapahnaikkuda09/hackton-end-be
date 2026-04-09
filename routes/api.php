<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScanResultController; 
use App\Http\Controllers\ProjectController; // Jangan lupa import di atas




Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/cicd/scans', [ScanResultController::class, 'store']);

Route::get('/cicd/scans/stats', [ScanResultController::class, 'stats']);

// Route untuk mendaftarkan repo baru dan mendapatkan Token
Route::post('/projects', [ProjectController::class, 'store']);