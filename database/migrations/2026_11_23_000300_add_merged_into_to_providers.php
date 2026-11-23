<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider soft-deleted by an approved English/Arabic pair (PairReviewService) is a merge shell,
 * not a deleted provider: it records the canonical provider it was merged into. Shells are excluded
 * from "deleted" counts, lists, restore and exports; only an undo of the pair re-splits them (ADR-017).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('migration_batch_id')->constrained('providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
