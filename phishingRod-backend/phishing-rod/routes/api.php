<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ScanController;

Route::post('/scans', [ScanController::class, 'store']);
Route::get('/scans/{uuid}', [ScanController::class, 'show']);
Route::get('/scans', [ScanController::class, 'index']);