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
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->json('form_snapshot')->nullable()->after('form_data');
        });

        DB::table('form_submissions')
            ->orderBy('id')
            ->chunkById(100, function ($submissions) {
                $formIds = collect($submissions)
                    ->pluck('form_id')
                    ->filter()
                    ->unique()
                    ->values();

                if ($formIds->isEmpty()) {
                    return;
                }

                $formConfigs = DB::table('forms')
                    ->whereIn('id', $formIds)
                    ->pluck('form_config', 'id');

                foreach ($submissions as $submission) {
                    $snapshot = $formConfigs->get($submission->form_id);

                    if ($snapshot === null) {
                        continue;
                    }

                    DB::table('form_submissions')
                        ->where('id', $submission->id)
                        ->update([
                            'form_snapshot' => $snapshot,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropColumn('form_snapshot');
        });
    }
};
