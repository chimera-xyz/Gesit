<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sections', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->longText('attachment_text')->nullable()->after('attachment_size');
        });

        $spaceIds = DB::table('knowledge_spaces')->pluck('id');
        $now = now();

        foreach ($spaceIds as $spaceId) {
            $defaultSectionId = DB::table('knowledge_sections')
                ->where('knowledge_space_id', $spaceId)
                ->where('name', 'Knowledge Base')
                ->orderBy('id')
                ->value('id');

            if (! $defaultSectionId) {
                $defaultSectionId = DB::table('knowledge_sections')->insertGetId([
                    'knowledge_space_id' => $spaceId,
                    'name' => 'Knowledge Base',
                    'description' => 'Section internal default untuk dokumen knowledge.',
                    'sort_order' => 0,
                    'is_active' => true,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('knowledge_sections')
                ->where('knowledge_space_id', $spaceId)
                ->update([
                    'is_default' => false,
                    'updated_at' => $now,
                ]);

            DB::table('knowledge_sections')
                ->where('id', $defaultSectionId)
                ->update([
                    'is_default' => true,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('attachment_text');
        });

        Schema::table('knowledge_sections', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
