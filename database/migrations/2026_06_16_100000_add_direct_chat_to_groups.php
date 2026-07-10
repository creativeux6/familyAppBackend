<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->enum('type', ['group', 'direct'])->default('group')->after('uuid');
            $table->string('direct_key', 64)->nullable()->unique()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropUnique(['direct_key']);
            $table->dropColumn(['type', 'direct_key']);
        });
    }
};
