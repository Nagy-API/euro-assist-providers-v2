<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Import/export retention and the Plesk-friendly queue fallback.
| Plesk's scheduler runs `php artisan schedule:run` every minute; with no long-running supervisor the
| scheduled `queue:work --stop-when-empty` drains the database queue (import validation/apply and
| export generation are queued jobs). withoutOverlapping() uses an atomic cache lock so two workers
| never run at once. A real supervisor (queue:work as a service) is preferable when available.
*/
Schedule::command('imports:prune')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('exports:prune')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('queue:work database --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
