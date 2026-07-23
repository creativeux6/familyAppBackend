<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_master_key')->nullable()->after('storage_read_bytes');
            $table->string('avatar_thumb_key')->nullable()->after('avatar_master_key');
            $table->unsignedInteger('avatar_master_bytes')->default(0)->after('avatar_thumb_key');
            $table->unsignedInteger('avatar_thumb_bytes')->default(0)->after('avatar_master_bytes');
            $table->timestamp('avatar_updated_at')->nullable()->after('avatar_thumb_bytes');
        });

        Schema::table('family_members', function (Blueprint $table) {
            $table->string('avatar_master_key')->nullable()->after('match_confidence');
            $table->string('avatar_thumb_key')->nullable()->after('avatar_master_key');
            $table->unsignedInteger('avatar_master_bytes')->default(0)->after('avatar_thumb_key');
            $table->unsignedInteger('avatar_thumb_bytes')->default(0)->after('avatar_master_bytes');
            $table->timestamp('avatar_updated_at')->nullable()->after('avatar_thumb_bytes');
            $table->foreignId('avatar_updated_by_user_id')
                ->nullable()
                ->after('avatar_updated_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_updated_by_user_id');
            $table->dropColumn([
                'avatar_master_key',
                'avatar_thumb_key',
                'avatar_master_bytes',
                'avatar_thumb_bytes',
                'avatar_updated_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_master_key',
                'avatar_thumb_key',
                'avatar_master_bytes',
                'avatar_thumb_bytes',
                'avatar_updated_at',
            ]);
        });
    }
};
