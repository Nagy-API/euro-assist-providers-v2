<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs after the application is built but before setUpTraits(), so the guard executes
     * before RefreshDatabase / DatabaseMigrations can touch any database.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->guardTestDatabase($this->app);

        // Tests must not depend on, or reuse, any developer encryption key.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    protected function guardTestDatabase(Application $app): void
    {
        $driver = (string) $app['config']->get('database.default');

        TestDatabaseGuard::assertSafe(
            $app->environment(),
            $app->configurationIsCached(),
            $driver,
            (array) $app['config']->get('database.connections.'.$driver, []),
        );
    }
}
