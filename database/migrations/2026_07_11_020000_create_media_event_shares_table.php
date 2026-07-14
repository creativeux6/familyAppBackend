<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_event_shares', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('media_event_uuid')
                ->constrained('media_events', 'uuid')
                ->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')->constrained('users');
            $table->string('access', 16)->default('view');
            $table->string('alias_title')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['media_event_uuid', 'recipient_user_id']);
            $table->index(['recipient_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_event_shares');
    }
};
