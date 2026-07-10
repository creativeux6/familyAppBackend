<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_encryption_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('group_uuid')->constrained('groups', 'uuid')->cascadeOnDelete();
            $table->unsignedInteger('generation')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('reason');
            $table->timestamps();

            $table->unique(['group_uuid', 'generation']);
        });

        Schema::create('group_key_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('group_uuid')->constrained('groups', 'uuid')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->binary('wrapped_group_key');
            $table->unsignedTinyInteger('encryption_version')->default(1);
            $table->timestamps();

            $table->unique(['group_uuid', 'generation', 'recipient_user_id'], 'group_key_env_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_key_envelopes');
        Schema::dropIfExists('group_encryption_generations');
    }
};
