<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source-to-target identity mapping for the legacy migration (ADR-009, D6).
 * Identity is NEVER inferred from numeric ID, name or phone alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw, untransformed source rows kept in the local database only (never in Git).
        Schema::create('legacy_staging_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_batch_id')->constrained('legacy_migration_batches')->cascadeOnDelete();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_locale', 5)->nullable();
            $table->char('row_hash', 64);
            $table->json('payload');
            // staged | mapped | needs_review | rejected  (every source row ends in exactly one)
            $table->string('state', 20)->default('staged');
            $table->string('reason')->nullable();
            $table->timestamps();

            // Explicit short name: the generated one exceeds MySQL's 64-character identifier limit.
            $table->unique(['migration_batch_id', 'source_table', 'source_id'], 'legacy_staging_batch_source_unique');
            $table->index(['migration_batch_id', 'state']);
        });

        Schema::create('legacy_id_maps', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_locale', 5)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('featured_provider_id')->nullable()->constrained('featured_providers')->nullOnDelete();
            // unpaired | suggested | approved | rejected
            $table->string('status', 20)->default('unpaired');
            $table->string('match_method', 40)->nullable();
            $table->json('review_evidence')->nullable(); // signal names only, no provider values
            $table->char('snapshot_sha256', 64);
            $table->foreignId('migration_batch_id')->constrained('legacy_migration_batches')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['status']);
            $table->index('provider_id');
        });

        Schema::create('legacy_pair_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_batch_id')->constrained('legacy_migration_batches')->cascadeOnDelete();
            $table->foreignId('left_map_id')->constrained('legacy_id_maps')->cascadeOnDelete();
            $table->foreignId('right_map_id')->constrained('legacy_id_maps')->cascadeOnDelete();
            $table->json('signals');                      // e.g. ["same_id","same_phone"]
            $table->unsignedSmallInteger('signal_count')->default(0);
            $table->string('status', 20)->default('suggested'); // suggested|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['left_map_id', 'right_map_id']);
            $table->index(['migration_batch_id', 'status']);
        });

        // Versioned mapping of raw legacy type labels -> provider_types.key (D3).
        Schema::create('legacy_type_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mapping_version', 40);
            $table->string('raw_value', 255);
            $table->foreignId('provider_type_id')->nullable()->constrained('provider_types')->restrictOnDelete();
            $table->string('status', 20)->default('needs_review'); // approved|needs_review
            $table->timestamps();

            $table->unique(['mapping_version', 'raw_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_type_mappings');
        Schema::dropIfExists('legacy_pair_suggestions');
        Schema::dropIfExists('legacy_id_maps');
        Schema::dropIfExists('legacy_staging_rows');
    }
};
