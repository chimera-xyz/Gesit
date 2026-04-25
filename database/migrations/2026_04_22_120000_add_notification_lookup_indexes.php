<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'id'], 'notifications_user_id_id_index');
            $table->index(['user_id', 'is_read', 'id'], 'notifications_user_read_id_index');
            $table->index(['user_id', 'created_at'], 'notifications_user_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_id_id_index');
            $table->dropIndex('notifications_user_read_id_index');
            $table->dropIndex('notifications_user_created_at_index');
        });
    }
};
