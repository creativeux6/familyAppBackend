<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_notification_deliveries', function (Blueprint $table) {
            $table->foreignUuid('calendar_reminder_uuid')
                ->nullable()
                ->after('source_member_uuid')
                ->constrained('calendar_reminders', 'uuid')
                ->nullOnDelete();

            $table->index(
                ['recipient_user_id', 'calendar_reminder_uuid', 'notification_kind', 'occurrence_date'],
                'calendar_reminder_delivery_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('calendar_notification_deliveries', function (Blueprint $table) {
            $table->dropIndex('calendar_reminder_delivery_idx');
            $table->dropConstrainedForeignId('calendar_reminder_uuid');
        });
    }
};
