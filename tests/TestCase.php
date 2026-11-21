<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $driver = config('database.default');
        $connection = config('database.connections.'.$driver);
        $isolatedSqlite = $driver === 'sqlite' && ($connection['database'] ?? null) === ':memory:';
        $isolatedMysql = $driver === 'mysql'
            && ($connection['host'] ?? null) === '127.0.0.1'
            && ($connection['database'] ?? null) === 'euro_assist_v2_test';

        if (! app()->environment('testing') || app()->configurationIsCached()
            || ! empty($connection['url']) || (! $isolatedSqlite && ! $isolatedMysql)) {
            throw new RuntimeException('Tests require uncached configuration and an isolated test database.');
        }

        // Tests must not depend on, or reuse, any developer encryption key.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }
}
