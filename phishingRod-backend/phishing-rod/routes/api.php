<?php

use App\Http\Controllers\Api\ScanController;
use Illuminate\Support\Facades\Route;

// Scan creation is rate-limited aggressively; polling is more generous.
Route::post('/scans', [ScanController::class, 'store'])->middleware('throttle:5,1');
Route::get('/scans/{uuid}', [ScanController::class, 'show'])->middleware('throttle:60,1');
Route::get('/scans', [ScanController::class, 'index'])->middleware('throttle:30,1');
