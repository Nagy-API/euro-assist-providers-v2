<?php

use App\Livewire\Migration\ReviewIndex;
use Illuminate\Support\Facades\Route;

// Included inside Route::middleware(['auth', 'active']) from routes/web.php.
// Legacy migration review-queue routes (Admin, permission migration.review) are registered here.
// The `can:` middleware is the route-level gate; every Livewire action re-authorizes itself.

Route::get('/migration/review', ReviewIndex::class)->middleware('can:migration.review')->name('migration.review.index');
