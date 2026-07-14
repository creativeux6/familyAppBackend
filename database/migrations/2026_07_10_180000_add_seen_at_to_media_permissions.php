<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_permissions', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable()->after('granted_by_user_id');
            $table->index(['user_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_permissions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'seen_at']);
            $table->dropColumn('seen_at');
        });
    }
};
