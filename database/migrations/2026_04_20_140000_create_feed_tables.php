<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feed_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('visibility', 20)->default('public');
            $table->string('target_department')->nullable();
            $table->text('content');
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['visibility', 'last_activity_at']);
            $table->index(['target_department', 'last_activity_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('feed_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('feed_comments')->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
            $table->index(['parent_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('feed_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('likeable_type', 120);
            $table->unsignedBigInteger('likeable_id');
            $table->timestamps();

            $table->index(['likeable_type', 'likeable_id'], 'feed_likes_likeable_index');
            $table->unique(
                ['user_id', 'likeable_type', 'likeable_id'],
                'feed_likes_user_likeable_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_likes');
        Schema::dropIfExists('feed_comments');
        Schema::dropIfExists('feed_posts');
    }
};
