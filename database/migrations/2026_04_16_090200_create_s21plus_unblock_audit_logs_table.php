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
        Schema::create('s21plus_unblock_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gesit_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('gesit_user_name', 255);
            $table->string('s21plus_user_id', 120)->nullable();
            $table->foreignId('knowledge_conversation_id')->nullable()->constrained('knowledge_conversations')->nullOnDelete();
            $table->foreignId('knowledge_conversation_message_id')->nullable()->constrained('knowledge_conversation_messages')->nullOnDelete();
            $table->string('request_type', 40);
            $table->boolean('before_is_enabled')->nullable();
            $table->unsignedInteger('before_login_retry')->nullable();
            $table->boolean('after_is_enabled')->nullable();
            $table->unsignedInteger('after_login_retry')->nullable();
            $table->string('status', 40);
            $table->string('result_code', 80);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['gesit_user_id', 'created_at']);
            $table->index(['s21plus_user_id', 'created_at']);
            $table->index(['request_type', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s21plus_unblock_audit_logs');
    }
};
