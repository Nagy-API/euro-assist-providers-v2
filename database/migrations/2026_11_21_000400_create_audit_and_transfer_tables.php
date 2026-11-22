<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('result', 20)->default('success'); // success|denied|failure
            $table->json('metadata')->nullable();             // minimal, never raw provider records
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index(['target_type', 'target_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('mode', 20);   // create_only|update_only|upsert|dry_run
            // uploaded|validating|validation_failed|ready|applying|completed|interrupted|conflict|failed|cancelled
            $table->string('status', 24)->default('uploaded');
            $table->string('original_filename');
            $table->string('stored_path', 500);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('template_version', 20)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('insert_count')->default(0);
            $table->unsignedInteger('update_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);   // committed work only
            $table->unsignedInteger('chunk_size')->default(200);
            $table->unsignedInteger('attempts')->default(0);
            $table->json('error_summary')->nullable();
            $table->string('error_report_path', 500)->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['file_sha256', 'status']);
            $table->index('status');
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');            // 1-based spreadsheet data row
            $table->string('action', 12);                     // create|update|unchanged|error
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->unsignedInteger('base_revision')->nullable();
            $table->json('payload');                          // normalized incoming values only
            $table->json('diff')->nullable();
            $table->json('errors')->nullable();
            $table->string('apply_status', 12)->default('pending'); // pending|applied|skipped|conflict
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['import_job_id', 'row_number']);
            $table->index(['import_job_id', 'apply_status', 'row_number']);
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('scope', 12);                      // all|filtered|selected
            $table->string('locale', 5);                      // ar|en
            $table->json('filters')->nullable();
            $table->json('selected_ids')->nullable();
            $table->string('status', 16)->default('queued');  // queued|running|completed|failed|expired
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('written_rows')->default(0);
            $table->string('file_path', 500)->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('audit_events');
    }
};
