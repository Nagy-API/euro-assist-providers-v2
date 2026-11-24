<?php

use App\Http\Controllers\Admin\FeaturedLogoController;
use App\Http\Controllers\Admin\ProviderMediaController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Dashboard;
use App\Livewire\Featured\Index as FeaturedIndex;
use App\Livewire\Providers\Form as ProviderForm;
use App\Livewire\Providers\Index as ProviderIndex;
use App\Livewire\Providers\Show as ProviderShow;
use App\Livewire\Types\Index as TypeIndex;
use App\Livewire\Users\Index as UserIndex;
use Illuminate\Support\Facades\Route;

// Included inside Route::middleware(['auth', 'active']) from routes/web.php.
// The `can:` middleware is the route-level gate; every Livewire action re-authorizes itself because
// Livewire update requests do not pass through these route middleware.

Route::get('/dashboard', Dashboard::class)->middleware('can:providers.view')->name('dashboard');

Route::prefix('providers')->name('providers.')->group(function () {
    Route::get('/', ProviderIndex::class)->middleware('can:providers.view')->name('index');
    Route::get('/create', ProviderForm::class)->middleware('can:providers.create')->name('create');
    Route::get('/{provider}', ProviderShow::class)->whereNumber('provider')->withTrashed()->middleware('can:providers.view')->name('show');
    Route::get('/{provider}/edit', ProviderForm::class)->whereNumber('provider')->middleware('can:providers.update')->name('edit');
    Route::get('/{provider}/photo', [ProviderMediaController::class, 'photo'])->whereNumber('provider')->middleware('can:providers.view')->name('photo');
});

Route::get('/types/{providerType}/icon', [ProviderMediaController::class, 'typeIcon'])->whereNumber('providerType')->middleware('can:providers.view')->name('types.icon');

Route::get('/types', TypeIndex::class)->middleware('can:types.manage')->name('types.index');

Route::get('/featured', FeaturedIndex::class)->middleware('can:providers.view')->name('featured.index');
Route::get('/featured/{featured}/logo', FeaturedLogoController::class)->whereNumber('featured')->middleware('can:providers.view')->name('featured.logo');

Route::get('/users', UserIndex::class)->middleware('can:users.manage')->name('users.index');

Route::get('/audit', AuditIndex::class)->middleware('can:audit.view')->name('audit.index');

Route::get('/settings/status', SystemStatusController::class)->middleware('can:settings.manage')->name('settings.status');
