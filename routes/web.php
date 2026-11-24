<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'generate' : 'login'));

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::get('/dashboard', fn () => redirect()->route('generate'))->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/generate', [GenerationController::class, 'create'])->name('generate');
    Route::post('/generate', [GenerationController::class, 'store'])->name('generate.store');
    Route::get('/history', [GenerationController::class, 'index'])->name('history');
    Route::post('/credits', [CreditController::class, 'buy'])->name('credits.buy');
    Route::get('/admin/report', ReportController::class)->name('admin.report');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
