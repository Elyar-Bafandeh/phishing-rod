<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScanController;

Route::get('/', [ScanController::class, 'create'])->name('scans.create');
Route::post('/scans', [ScanController::class, 'store'])->name('scans.store');
Route::get('/scans/{uuid}', [ScanController::class, 'show'])->name('scans.show');
