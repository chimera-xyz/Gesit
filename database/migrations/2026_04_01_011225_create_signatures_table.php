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
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('signature_image'); // Path to signature image
            $table->enum('signature_type', ['draw', 'upload']); // Draw or Upload
            $table->text('signature_hash')->nullable(); // For authenticity verification
            $table->json('metadata')->nullable(); // Additional metadata for verification
            $table->boolean('verified')->default(false);
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
