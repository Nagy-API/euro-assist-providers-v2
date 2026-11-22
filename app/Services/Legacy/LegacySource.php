<?php

namespace App\Services\Legacy;

use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only access to the frozen legacy audit database. The ONLY queries issued through this
 * class are SELECTs on a fixed table allow-list; the connection itself is additionally switched
 * to a read-only session (config/database.php). Nothing here ever writes, migrates or alters.
 */
class LegacySource
{
    public const TABLES = ['providers', 'arabic_providers', 'top_providers', 'types'];

    public function connection(): ConnectionInterface
    {
        return DB::connection((string) config('legacy.connection'));
    }

    public function count(string $table): int
    {
        return (int) $this->connection()->table($this->table($table))->count();
    }

    /** True when a SELECT succeeds; used to decide whether the local audit database is reachable. */
    public function reachable(): bool
    {
        try {
            $this->count('types');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rows in primary-key order, as chunks of plain arrays (keyset pagination: bounded memory).
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function chunks(string $table, int $size = 500): Generator
    {
        $table = $this->table($table);
        $last = 0;

        while (true) {
            $rows = $this->connection()->table($table)
                ->where('id', '>', $last)->orderBy('id')->limit($size)->get();
            if ($rows->isEmpty()) {
                return;
            }

            $chunk = $rows->map(fn ($row) => (array) $row)->all();
            $last = (int) end($chunk)['id'];

            yield array_values($chunk);

            if (count($chunk) < $size) {
                return;
            }
        }
    }

    /** @return list<array<string, mixed>> every row of a small reference table (types only). */
    public function all(string $table): array
    {
        return $this->connection()->table($this->table($table))->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
    }

    private function table(string $table): string
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new LegacyException("Table '{$table}' is not part of the legacy source allow-list.");
        }

        return $table;
    }
}
