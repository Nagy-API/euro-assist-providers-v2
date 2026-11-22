<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every approve / reject / undo of a bilingual pair records reviewer, action, time and a required
 * free-text reason. Kept on the suggestion (local review data), never copied into audit metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_pair_suggestions', function (Blueprint $table) {
            $table->json('review_history')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_pair_suggestions', function (Blueprint $table) {
            $table->dropColumn('review_history');
        });
    }
};
