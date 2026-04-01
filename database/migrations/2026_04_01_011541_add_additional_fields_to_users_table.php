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
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id')->nullable()->unique(); // Employee ID for identification
            $table->string('department')->nullable(); // Department name
            $table->string('phone_number')->nullable(); // Contact number
            $table->text('signature_path')->nullable(); // Pre-saved signature image path
            $table->boolean('is_active')->default(true); // Active user status
            $table->softDeletes(); // Soft delete for users
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employee_id', 'department', 'phone_number', 'signature_path', 'is_active']);
            $table->dropSoftDeletes();
        });
    }
};
