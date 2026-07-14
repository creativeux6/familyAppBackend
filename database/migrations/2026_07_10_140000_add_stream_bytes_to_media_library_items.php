<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_library_items', function (Blueprint $table) {
            $table->unsignedBigInteger('stream_bytes_charged')->default(0)->after('quota_charged_at');
        });
    }

    public function down(): void
    {
        Schema::table('media_library_items', function (Blueprint $table) {
            $table->dropColumn('stream_bytes_charged');
        });
    }
};
