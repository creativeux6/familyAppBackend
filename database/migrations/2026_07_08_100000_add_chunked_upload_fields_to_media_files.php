<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->string('multipart_upload_id')->nullable()->after('s3_key');
            $table->json('uploaded_parts')->nullable()->after('multipart_upload_id');
            $table->unsignedInteger('chunk_size')->nullable()->after('uploaded_parts');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['multipart_upload_id', 'uploaded_parts', 'chunk_size']);
        });
    }
};
