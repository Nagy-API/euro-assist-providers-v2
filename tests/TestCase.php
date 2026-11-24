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

    /**
     * Pages are rendered with the Vite helper stubbed: the PHP test jobs have no frontend build
     * (public/build is not committed), and the build itself is verified by the CI `assets` job.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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
