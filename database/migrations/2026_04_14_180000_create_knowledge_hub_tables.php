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
        Schema::create('knowledge_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('knowledge_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_space_id')->constrained('knowledge_spaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_section_id')->constrained('knowledge_sections')->cascadeOnDelete();
            $table->string('title');
            $table->string('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('scope')->default('internal');
            $table->string('type')->default('sop');
            $table->string('source_kind')->default('article');
            $table->string('owner_name')->nullable();
            $table->string('reviewer_name')->nullable();
            $table->string('version_label')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('reference_notes')->nullable();
            $table->string('source_link')->nullable();
            $table->json('tags')->nullable();
            $table->string('access_mode')->default('all');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'type', 'is_active']);
        });

        Schema::create('knowledge_entry_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->unique(['knowledge_entry_id', 'role_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('knowledge_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('knowledge_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'knowledge_entry_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_bookmarks');
        Schema::dropIfExists('knowledge_entry_role');
        Schema::dropIfExists('knowledge_entries');
        Schema::dropIfExists('knowledge_sections');
        Schema::dropIfExists('knowledge_spaces');
    }
};
