<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->string('channel', 32)->default('production');
            $table->string('version_name', 50);
            $table->unsignedInteger('version_code');
            $table->unsignedInteger('minimum_supported_version_code');
            $table->text('release_notes')->nullable();
            $table->string('apk_path');
            $table->string('apk_file_name');
            $table->string('apk_mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'channel', 'version_code'], 'mobile_app_releases_platform_channel_version_unique');
            $table->index(['platform', 'channel', 'is_published', 'version_code'], 'mobile_app_releases_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_releases');
    }
};
