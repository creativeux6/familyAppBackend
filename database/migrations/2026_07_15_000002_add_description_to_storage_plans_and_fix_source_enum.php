<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_plans', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        // Free-plan auto-assign uses system_default (was missing from original enum).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_plan_assignments MODIFY COLUMN source ENUM('admin_manual', 'payment', 'system_default') NOT NULL DEFAULT 'admin_manual'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_plan_assignments MODIFY COLUMN source ENUM('admin_manual', 'payment') NOT NULL DEFAULT 'admin_manual'");
        }

        Schema::table('storage_plans', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
