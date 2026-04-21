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
        Schema::table('feed_comments', function (Blueprint $table) {
            $table->foreignId('reply_to_comment_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('feed_comments')
                ->nullOnDelete();
            $table->foreignId('reply_to_user_id')
                ->nullable()
                ->after('reply_to_comment_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        $parentAuthorIds = DB::table('feed_comments')->pluck('user_id', 'id');

        DB::table('feed_comments')
            ->whereNotNull('parent_id')
            ->orderBy('id')
            ->select(['id', 'parent_id'])
            ->chunkById(200, function ($comments) use ($parentAuthorIds) {
                foreach ($comments as $comment) {
                    $parentId = (int) $comment->parent_id;

                    DB::table('feed_comments')
                        ->where('id', $comment->id)
                        ->update([
                            'reply_to_comment_id' => $parentId,
                            'reply_to_user_id' => $parentAuthorIds->get($parentId),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feed_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_user_id');
            $table->dropConstrainedForeignId('reply_to_comment_id');
        });
    }
};
