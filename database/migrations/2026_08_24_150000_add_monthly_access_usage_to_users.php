<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_read_period_bytes')->default(0)->after('storage_read_bytes');
            $table->timestamp('storage_read_period_ends_at')->nullable()->after('storage_read_period_bytes');
            $table->unsignedTinyInteger('storage_access_warn_level')->default(0)->after('storage_read_period_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'storage_read_period_bytes',
                'storage_read_period_ends_at',
                'storage_access_warn_level',
            ]);
        });
    }
};
