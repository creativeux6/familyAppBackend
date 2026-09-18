<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $drop = [];
            foreach ([
                'storage_used_bytes',
                'storage_read_bytes',
                'storage_read_period_bytes',
                'storage_read_period_ends_at',
                'storage_access_warn_level',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('user_storage_usage', function (Blueprint $table) {
            if (! Schema::hasColumn('user_storage_usage', 'access_warn_level')) {
                $table->unsignedTinyInteger('access_warn_level')->default(0)->after('file_view_requests');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'storage_used_bytes')) {
                $table->unsignedBigInteger('storage_used_bytes')->default(0);
            }
            if (! Schema::hasColumn('users', 'storage_read_bytes')) {
                $table->unsignedBigInteger('storage_read_bytes')->default(0);
            }
            if (! Schema::hasColumn('users', 'storage_read_period_bytes')) {
                $table->unsignedBigInteger('storage_read_period_bytes')->default(0);
            }
            if (! Schema::hasColumn('users', 'storage_read_period_ends_at')) {
                $table->timestamp('storage_read_period_ends_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'storage_access_warn_level')) {
                $table->unsignedTinyInteger('storage_access_warn_level')->default(0);
            }
        });

        Schema::table('user_storage_usage', function (Blueprint $table) {
            if (Schema::hasColumn('user_storage_usage', 'access_warn_level')) {
                $table->dropColumn('access_warn_level');
            }
        });
    }
};
