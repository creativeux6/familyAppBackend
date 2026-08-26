<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('client_message_id')->nullable()->after('uuid');
            $table->unique(
                ['group_uuid', 'sender_user_id', 'client_message_id'],
                'messages_group_sender_client_message_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_group_sender_client_message_unique');
            $table->dropColumn('client_message_id');
        });
    }
};
