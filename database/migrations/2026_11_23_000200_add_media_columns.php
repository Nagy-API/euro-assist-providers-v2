<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-managed media (ADR-018). `providers.logo_path` keeps the RAW legacy reference unchanged (it is
 * part of the migration fidelity contract); the image V2 actually shows lives in `photo_path`, a
 * path on the private disk produced by ProviderPhotoStore. Provider types get the V1 per-type
 * fallback icon in `icon_path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('logo_path');
        });
        Schema::table('provider_types', function (Blueprint $table) {
            $table->string('icon_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
        Schema::table('provider_types', function (Blueprint $table) {
            $table->dropColumn('icon_path');
        });
    }
};
