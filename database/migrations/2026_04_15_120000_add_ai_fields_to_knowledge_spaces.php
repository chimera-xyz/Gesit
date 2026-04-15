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
            $table->string('kind', 20)->default('division')->after('name');
            $table->text('ai_instruction')->nullable()->after('description');
            $table->longText('knowledge_text')->nullable()->after('ai_instruction');
            $table->boolean('show_in_hub')->default(true)->after('is_active');
            $table->index(['kind', 'show_in_hub', 'is_active'], 'knowledge_spaces_kind_hub_active_idx');
        });

        DB::table('knowledge_spaces')->update([
            'kind' => 'division',
            'show_in_hub' => true,
        ]);

        $generalSpaceId = DB::table('knowledge_spaces')
            ->where('kind', 'general')
            ->value('id');

        if (! $generalSpaceId) {
            $timestamp = now();
            $generalSpaceId = DB::table('knowledge_spaces')->insertGetId([
                'name' => 'General Knowledge',
                'kind' => 'general',
                'description' => 'Knowledge umum perusahaan untuk AI Assistant.',
                'icon' => 'sparkles',
                'sort_order' => 0,
                'is_active' => true,
                'show_in_hub' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } else {
            DB::table('knowledge_spaces')
                ->where('id', $generalSpaceId)
                ->update([
                    'show_in_hub' => false,
                    'updated_at' => now(),
                ]);
        }

        $sectionExists = DB::table('knowledge_sections')
            ->where('knowledge_space_id', $generalSpaceId)
            ->exists();

        if (! $sectionExists) {
            $timestamp = now();

            DB::table('knowledge_sections')->insert([
                'knowledge_space_id' => $generalSpaceId,
                'name' => 'Knowledge Base',
                'description' => 'Section internal untuk knowledge umum AI.',
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_spaces', function (Blueprint $table) {
            $table->dropIndex('knowledge_spaces_kind_hub_active_idx');
            $table->dropColumn([
                'kind',
                'ai_instruction',
                'knowledge_text',
                'show_in_hub',
            ]);
        });
    }
};
