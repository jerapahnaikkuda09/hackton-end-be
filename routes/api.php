<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\StoreLocalScanController;

Route::post('/v1/scans', [StoreLocalScanController::class, 'store']);
