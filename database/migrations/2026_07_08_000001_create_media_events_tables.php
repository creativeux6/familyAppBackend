<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_events', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('event_date')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id', 'created_at']);
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignUuid('media_event_uuid')
                ->nullable()
                ->after('status')
                ->constrained('media_events', 'uuid')
                ->nullOnDelete();

            $table->index(['owner_user_id', 'media_event_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_event_uuid');
        });

        Schema::dropIfExists('media_events');
    }
};
