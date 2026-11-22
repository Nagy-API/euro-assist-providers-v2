<?php

use App\Livewire\Migration\ReviewIndex;
use Illuminate\Support\Facades\Route;

// Included inside Route::middleware(['auth', 'active']) from routes/web.php.
// Legacy migration review-queue routes (Admin, permission migration.review) are registered here.
// The `can:` middleware is the route-level gate; every Livewire action re-authorizes itself.

// LOCAL-ONLY (ADR-014): the review queue is not even registered outside the allowed environments,
// so staging/production expose no route and no navigation entry for it.
if (in_array(app()->environment(), (array) config('legacy.allowed_environments', []), true) && ! app()->environment('production')) {
    Route::get('/migration/review', ReviewIndex::class)->middleware('can:migration.review')->name('migration.review.index');
}
