<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('joined_at');
            $table->uuid('last_read_message_uuid')->nullable()->after('last_read_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('media_file_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn(['last_read_at', 'last_read_message_uuid']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
