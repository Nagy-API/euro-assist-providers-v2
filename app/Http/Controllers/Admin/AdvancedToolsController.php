<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Advanced administration: a landing page for rarely used tools. It only LINKS to them; every tool keeps
 * its own route middleware, policy and server-side checks, so moving the menu entry here changes no access
 * rule. The migration review link is shown only where that route exists and the viewer holds
 * migration.review (the route itself is never registered in production, ADR-014).
 */
class AdvancedToolsController extends Controller
{
    public function __invoke(): View
    {
        Gate::authorize('settings.manage');

        return view('settings.advanced', [
            'reviewAvailable' => Route::has('migration.review.index') && Gate::allows('migration.review'),
            'auditAvailable' => Route::has('audit.index') && Gate::allows('audit.view'),
        ]);
    }
}
