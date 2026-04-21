<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_spaces', function (Blueprint $table) {
            $table->string('ai_provider', 20)->default('zai')->after('knowledge_text');
            $table->string('ai_local_base_url')->nullable()->after('ai_provider');
            $table->text('ai_local_api_key')->nullable()->after('ai_local_base_url');
            $table->string('ai_local_model', 120)->nullable()->after('ai_local_api_key');
            $table->unsignedInteger('ai_local_timeout')->nullable()->after('ai_local_model');
        });

        DB::table('knowledge_spaces')
            ->whereNull('ai_provider')
            ->update([
                'ai_provider' => 'zai',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_spaces', function (Blueprint $table) {
            $table->dropColumn([
                'ai_provider',
                'ai_local_base_url',
                'ai_local_api_key',
                'ai_local_model',
                'ai_local_timeout',
            ]);
        });
    }
};
