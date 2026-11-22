<?php

use App\Http\Controllers\Transfers\ExportController;
use App\Http\Controllers\Transfers\ImportController;
use App\Livewire\Transfers\ExportsIndex;
use App\Livewire\Transfers\ImportShow;
use App\Livewire\Transfers\ImportsIndex;
use App\Livewire\Transfers\JobsIndex;
use Illuminate\Support\Facades\Route;

// Included inside Route::middleware(['auth', 'active']) from routes/web.php.
// Import / export / job-history / download routes. Every one authorizes server-side through
// App\Services\Transfers\JobAccess (policies): Viewer 403, another user's job 404, Admin sees all.
// Mutations are plain POSTs (CSRF-protected by the web group), never Livewire actions.

Route::get('/imports', ImportsIndex::class)->name('imports.index');
Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('/imports/template', [ImportController::class, 'template'])->name('imports.template');
Route::get('/imports/{importJob}', ImportShow::class)->name('imports.show');
Route::post('/imports/{importJob}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
Route::post('/imports/{importJob}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
Route::post('/imports/{importJob}/retry', [ImportController::class, 'retry'])->name('imports.retry');
Route::get('/imports/{importJob}/errors', [ImportController::class, 'errorReport'])->name('imports.error-report');

Route::get('/exports', ExportsIndex::class)->name('exports.index');
Route::post('/exports', [ExportController::class, 'store'])->name('exports.store');
Route::get('/exports/{exportJob}/download', [ExportController::class, 'download'])->name('exports.download');

Route::get('/jobs', JobsIndex::class)->name('jobs.index');
