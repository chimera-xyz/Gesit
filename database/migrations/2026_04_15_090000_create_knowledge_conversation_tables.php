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
        Schema::create('knowledge_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('knowledge_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_conversation_id')->constrained('knowledge_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->string('scope')->nullable();
            $table->string('provider')->nullable();
            $table->json('sources')->nullable();
            $table->timestamps();

            $table->index(['knowledge_conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_conversation_messages');
        Schema::dropIfExists('knowledge_conversations');
    }
};
