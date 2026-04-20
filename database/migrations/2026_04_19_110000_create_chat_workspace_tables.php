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
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('slug')->nullable()->unique();
            $table->string('direct_hash')->nullable()->unique();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('accent_color')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->index(['type', 'last_message_at']);
        });

        Schema::create('chat_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_token')->nullable();
            $table->string('kind', 30)->default('text');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->unique(['sender_id', 'client_token']);
        });

        Schema::create('chat_conversation_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_muted')->default(false);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'is_pinned']);
            $table->index(['user_id', 'is_muted']);
        });

        Schema::create('chat_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_call_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('initiated_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('ringing');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['conversation_id', 'status']);
        });

        Schema::create('chat_call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_session_id')->constrained('chat_call_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 20)->default('invited');
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->unique(['call_session_id', 'user_id']);
            $table->index(['user_id', 'state']);
        });

        Schema::create('chat_user_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('call_session_id')->nullable();
            $table->string('event_type', 50);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['conversation_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_user_events');
        Schema::dropIfExists('chat_call_participants');
        Schema::dropIfExists('chat_call_sessions');
        Schema::dropIfExists('chat_message_attachments');
        Schema::dropIfExists('chat_conversation_user_states');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversation_participants');
        Schema::dropIfExists('chat_conversations');
    }
};
