<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            if (! Schema::hasColumn('groups', 'avatar_thumb_key')) {
                $table->string('avatar_master_key')->nullable()->after('description');
                $table->string('avatar_thumb_key')->nullable()->after('avatar_master_key');
                $table->unsignedBigInteger('avatar_master_bytes')->default(0)->after('avatar_thumb_key');
                $table->unsignedBigInteger('avatar_thumb_bytes')->default(0)->after('avatar_master_bytes');
                $table->timestamp('avatar_updated_at')->nullable()->after('avatar_thumb_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            foreach ([
                'avatar_master_key',
                'avatar_thumb_key',
                'avatar_master_bytes',
                'avatar_thumb_bytes',
                'avatar_updated_at',
            ] as $column) {
                if (Schema::hasColumn('groups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
