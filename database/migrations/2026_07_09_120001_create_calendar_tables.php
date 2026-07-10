<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_reminders', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('family_member_uuid')
                ->nullable()
                ->constrained('family_members', 'uuid')
                ->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->date('event_date');
            $table->string('event_type')->default('personal_reminder');
            $table->string('visibility')->default('personal');
            $table->unsignedTinyInteger('notify_days_before')->default(3);
            $table->boolean('recurring_yearly')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'event_date']);
            $table->index(['visibility', 'event_date']);
        });

        Schema::create('calendar_notification_deliveries', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('source_member_uuid')
                ->nullable()
                ->constrained('family_members', 'uuid')
                ->nullOnDelete();
            $table->string('event_type');
            $table->date('occurrence_date');
            $table->timestamp('notified_at');
            $table->timestamps();

            $table->unique(
                ['recipient_user_id', 'source_member_uuid', 'event_type', 'occurrence_date'],
                'calendar_delivery_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_notification_deliveries');
        Schema::dropIfExists('calendar_reminders');
    }
};
