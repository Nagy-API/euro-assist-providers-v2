<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Review note for a mapping that is not yet approved (e.g. plural display labels, decision D3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_type_mappings', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_type_mappings', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
