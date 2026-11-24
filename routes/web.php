<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::post('/locale/{locale}', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
| Everything below requires an authenticated, active account. Each included file owns one
| area and MUST authorize every action server-side (policies/gates), never only in the UI.
*/
Route::middleware(['auth', 'active', 'password.fresh'])->group(function () {
    // The only screens an account with a temporary password may use (ADR-019).
    Route::get('/account/password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/account/password', [PasswordChangeController::class, 'update'])->middleware('throttle:6,1')->name('password.change.update');

    require __DIR__.'/admin.php';      // dashboard, providers, types, featured, users, audit
    require __DIR__.'/transfers.php';  // import / export / job history / downloads
    require __DIR__.'/migration.php';  // legacy migration review queue (Admin)
});
