<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_library_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('media_file_uuid')->constrained('media_files', 'uuid')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('quota_charged_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['media_file_uuid', 'user_id']);
            $table->index(['user_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_library_items');
    }
};
