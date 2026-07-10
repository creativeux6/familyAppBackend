<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('onboarding_answers', 'relation_index')) {
            Schema::table('onboarding_answers', function (Blueprint $table) {
                $table->unsignedSmallInteger('relation_index')->default(0)->after('relative_slot');
            });
        }

        if (! Schema::hasColumn('onboarding_answers', 'gender')) {
            Schema::table('onboarding_answers', function (Blueprint $table) {
                $table->string('gender', 16)->nullable()->after('birthplace');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE onboarding_answers MODIFY relative_slot VARCHAR(64) NOT NULL');
        }

        Schema::table('onboarding_answers', function (Blueprint $table) {
            $table->index('onboarding_session_uuid', 'onboarding_answers_session_uuid_index');
        });

        Schema::table('onboarding_answers', function (Blueprint $table) {
            $table->dropUnique('onboarding_slot_unique');
            $table->unique(
                ['onboarding_session_uuid', 'relative_slot', 'relation_index'],
                'onboarding_slot_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_answers', function (Blueprint $table) {
            $table->dropUnique('onboarding_slot_unique');
        });

        Schema::table('onboarding_answers', function (Blueprint $table) {
            $table->unique(['onboarding_session_uuid', 'relative_slot'], 'onboarding_slot_unique');
            $table->dropIndex('onboarding_answers_session_uuid_index');
        });

        Schema::table('onboarding_answers', function (Blueprint $table) {
            if (Schema::hasColumn('onboarding_answers', 'relation_index')) {
                $table->dropColumn('relation_index');
            }
            if (Schema::hasColumn('onboarding_answers', 'gender')) {
                $table->dropColumn('gender');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE onboarding_answers MODIFY relative_slot ENUM(
                'self','father','mother',
                'paternal_grandfather','paternal_grandmother',
                'maternal_grandfather','maternal_grandmother',
                'other_relative'
            ) NOT NULL");
        }
    }
};
