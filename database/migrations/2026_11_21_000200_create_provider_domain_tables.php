<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical provider model (ADR-005/009; provisional decisions D1, D2, D4, D5 in docs/05).
 *
 * - providers: one row per distinct branch/service location; language-independent data only.
 * - provider_translations: localized free text, unique per (provider, locale).
 * - Free-text fields (phone, hours, discount, package) are lossless strings by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('snapshot_sha256', 64);
            $table->string('mapping_version', 40);
            $table->string('status', 20)->default('staged'); // staged|applied|rolled_back|failed
            $table->boolean('is_rehearsal')->default(true);
            $table->json('counts')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('provider_type_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_type_id')->constrained('provider_types')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->timestamps();
            $table->unique(['provider_type_id', 'locale']);
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_type_id')->constrained('provider_types')->restrictOnDelete();
            $table->string('phone', 500)->nullable();   // text: several numbers/separators, leading zeros
            $table->string('email')->nullable();
            $table->string('website', 500)->nullable();
            $table->string('map_url', 2048)->nullable(); // original text, never rewritten
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('logo_path', 500)->nullable(); // raw reference; media handling pending (D8)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1); // optimistic concurrency for imports
            $table->foreignId('migration_batch_id')->nullable()
                ->constrained('legacy_migration_batches')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('deleted_at');
            $table->index('updated_at');
            $table->index('migration_batch_id');
        });

        Schema::create('provider_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->string('address', 500);
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->text('specialization')->nullable();
            $table->text('hours')->nullable();
            $table->text('discount_text')->nullable();
            $table->text('package_text')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'locale']);
            $table->index(['locale', 'name']);
            $table->index(['locale', 'city']);
            $table->index(['locale', 'district']);
        });

        // Brand-level presentation records, intentionally NOT linked to providers (D5 provisional).
        Schema::create('featured_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->foreignId('provider_type_id')->nullable()->constrained('provider_types')->restrictOnDelete();
            $table->string('logo_path', 500)->nullable(); // private disk path
            $table->unsignedInteger('sort_order')->default(0); // new V2 capability, not a legacy rule
            $table->boolean('is_active')->default(true);       // new V2 capability, not a legacy rule
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_providers');
        Schema::dropIfExists('provider_translations');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('provider_type_translations');
        Schema::dropIfExists('provider_types');
        Schema::dropIfExists('legacy_migration_batches');
    }
};
