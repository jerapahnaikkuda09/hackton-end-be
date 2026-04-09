<?php

use Illuminate\Support\Facades\Route;

// Auth pages
Route::get('/login', fn() => view('auth.login'));
Route::get('/register', fn() => view('auth.register'));

// App pages (auth guard via JS)
Route::get('/', fn() => redirect('/dashboard'));
Route::get('/dashboard', fn() => view('dashboard'));
Route::get('/scans', fn() => view('scans.index'));
Route::get('/scans/{id}', fn() => view('scans.show'));
Route::get('/scan-requests', fn() => view('scan-requests.index'));

// Download scanner.py
Route::get('/scanner/download', function () {
    $path = base_path('scanner/scanner.py');
    if (!file_exists($path)) {
        abort(404, 'Scanner not found');
    }
    return response()->download($path, 'scanner.py', [
        'Content-Type' => 'text/x-python',
    ]);
});

