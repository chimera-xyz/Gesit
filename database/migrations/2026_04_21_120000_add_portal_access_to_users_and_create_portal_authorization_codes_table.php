<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('allowed_apps')->nullable()->after('is_active');
            $table->string('home_app', 80)->nullable()->after('allowed_apps');
        });

        Schema::create('portal_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_key', 80);
            $table->string('code', 120)->unique();
            $table->string('redirect_uri', 500);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['client_key', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_authorization_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['allowed_apps', 'home_app']);
        });
    }
};
