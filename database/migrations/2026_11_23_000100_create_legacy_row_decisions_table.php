<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-source-row owner decisions for the legacy migration (ADR-017).
 *
 * A row decision overrides ONE normalized target value of ONE staged source row (for example the
 * provider type of a single "Eye Center" row, or the repaired map link of a malformed URL). The raw
 * staging payload is never changed: the original value stays recoverable, and the decision records
 * who decided, why and from which reviewed manifest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_row_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_batch_id')->constrained('legacy_migration_batches')->cascadeOnDelete();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            // type_key | map_url
            $table->string('field', 40);
            $table->text('value');
            // SHA-256 of the original raw value this decision replaces (proves which value was reviewed).
            $table->char('original_sha256', 64)->nullable();
            $table->string('reason', 500);
            $table->char('manifest_sha256', 64)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['migration_batch_id', 'source_table', 'source_id', 'field'], 'legacy_row_decisions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_row_decisions');
    }
};
