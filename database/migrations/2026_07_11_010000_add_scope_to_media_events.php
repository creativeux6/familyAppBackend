<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_events', function (Blueprint $table) {
            $table->string('scope', 16)->default('private')->after('owner_user_id');
            $table->index(['owner_user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('media_events', function (Blueprint $table) {
            $table->dropIndex(['owner_user_id', 'scope']);
            $table->dropColumn('scope');
        });
    }
};
