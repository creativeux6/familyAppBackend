<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('calendar_notification_deliveries', 'notification_kind')) {
            Schema::table('calendar_notification_deliveries', function (Blueprint $table) {
                $table->string('notification_kind', 32)->default('upcoming')->after('event_type');
            });
        }

        Schema::table('calendar_notification_deliveries', function (Blueprint $table) {
            $table->index('recipient_user_id', 'calendar_deliveries_recipient_idx');
        });

        Schema::table('calendar_notification_deliveries', function (Blueprint $table) {
            $table->dropUnique('calendar_delivery_unique');
            $table->unique(
                ['recipient_user_id', 'source_member_uuid', 'event_type', 'occurrence_date', 'notification_kind'],
                'calendar_delivery_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('calendar_notification_deliveries', function (Blueprint $table) {
            $table->dropUnique('calendar_delivery_unique');
            $table->unique(
                ['recipient_user_id', 'source_member_uuid', 'event_type', 'occurrence_date'],
                'calendar_delivery_unique',
            );
            $table->dropIndex('calendar_deliveries_recipient_idx');
        });

        if (Schema::hasColumn('calendar_notification_deliveries', 'notification_kind')) {
            Schema::table('calendar_notification_deliveries', function (Blueprint $table) {
                $table->dropColumn('notification_kind');
            });
        }
    }
};
