<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('owner_user_id')->constrained('users');
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->string('s3_bucket');
            $table->string('s3_key');
            $table->string('display_name')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type')->default('application/octet-stream');
            $table->string('checksum_sha256', 64);
            $table->unsignedTinyInteger('encryption_version')->default(1);
            $table->enum('status', ['pending_upload', 'active', 'deleted'])->default('pending_upload');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('media_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('media_file_uuid')->constrained('media_files', 'uuid')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_uuid')->nullable()->constrained('groups', 'uuid')->cascadeOnDelete();
            $table->enum('access', ['view', 'owner']);
            $table->foreignId('granted_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['media_file_uuid', 'user_id']);
            $table->index(['media_file_uuid', 'group_uuid']);
        });

        Schema::create('media_key_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('media_file_uuid')->constrained('media_files', 'uuid')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->binary('wrapped_content_key');
            $table->unsignedTinyInteger('encryption_version')->default(1);
            $table->timestamps();

            $table->unique(['media_file_uuid', 'recipient_user_id']);
        });

        Schema::create('media_ownership_transfers', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('media_file_uuid')->constrained('media_files', 'uuid')->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('to_user_id')->constrained('users');
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled']);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_ownership_transfers');
        Schema::dropIfExists('media_key_envelopes');
        Schema::dropIfExists('media_permissions');
        Schema::dropIfExists('media_files');
    }
};
