<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('group_uuid')->constrained('groups', 'uuid')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('encryption_generation');
            $table->binary('ciphertext');
            $table->binary('nonce');
            $table->unsignedTinyInteger('encryption_version')->default(1);
            $table->enum('type', ['text', 'media_reference', 'system'])->default('text');
            $table->uuid('media_file_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['group_uuid', 'created_at']);
            $table->index(['group_uuid', 'encryption_generation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
