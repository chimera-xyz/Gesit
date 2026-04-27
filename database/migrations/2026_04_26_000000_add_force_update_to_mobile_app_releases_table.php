<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_releases', function (Blueprint $table) {
            $table->boolean('is_force_update')->default(false)->after('minimum_supported_version_code');
        });

        DB::table('mobile_app_releases')
            ->whereColumn('minimum_supported_version_code', '>=', 'version_code')
            ->update(['is_force_update' => true]);
    }

    public function down(): void
    {
        Schema::table('mobile_app_releases', function (Blueprint $table) {
            $table->dropColumn('is_force_update');
        });
    }
};
