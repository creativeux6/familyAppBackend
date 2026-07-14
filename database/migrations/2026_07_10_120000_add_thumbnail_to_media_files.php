<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->string('thumbnail_s3_key')->nullable()->after('s3_key');
            $table->unsignedInteger('thumbnail_size_bytes')->nullable()->after('thumbnail_s3_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_s3_key', 'thumbnail_size_bytes']);
        });
    }
};
