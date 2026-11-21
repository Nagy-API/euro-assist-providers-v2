<?php

use Illuminate\Support\Facades\Route;

// Included inside Route::middleware(['auth', 'active']) from routes/web.php.

Route::view('/dashboard', 'dashboard')->name('dashboard');
