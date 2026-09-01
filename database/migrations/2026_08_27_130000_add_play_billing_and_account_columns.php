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
            if (! Schema::hasColumn('storage_plans', 'play_product_id')) {
                $table->string('play_product_id', 128)->nullable()->after('slug');
            }
        });

        Schema::table('user_plan_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('user_plan_assignments', 'play_purchase_token')) {
                $table->string('play_purchase_token', 512)->nullable()->after('source');
                $table->index('play_purchase_token');
            }
            if (! Schema::hasColumn('user_plan_assignments', 'play_product_id')) {
                $table->string('play_product_id', 128)->nullable()->after('play_purchase_token');
            }
            if (! Schema::hasColumn('user_plan_assignments', 'play_auto_renewing')) {
                $table->boolean('play_auto_renewing')->default(false)->after('play_product_id');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_plan_assignments MODIFY COLUMN source ENUM('admin_manual', 'payment', 'system_default', 'google_play') NOT NULL DEFAULT 'admin_manual'");
        }

        $sku = [
            'personal' => 'tijori_personal',
            'plus' => 'tijori_plus',
            'pro' => 'tijori_pro',
        ];
        foreach ($sku as $slug => $productId) {
            DB::table('storage_plans')->where('slug', $slug)->update(['play_product_id' => $productId]);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_plan_assignments MODIFY COLUMN source ENUM('admin_manual', 'payment', 'system_default') NOT NULL DEFAULT 'admin_manual'");
        }

        Schema::table('user_plan_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('user_plan_assignments', 'play_purchase_token')) {
                $table->dropIndex(['play_purchase_token']);
                $table->dropColumn(['play_purchase_token', 'play_product_id', 'play_auto_renewing']);
            }
        });

        Schema::table('storage_plans', function (Blueprint $table) {
            if (Schema::hasColumn('storage_plans', 'play_product_id')) {
                $table->dropColumn('play_product_id');
            }
        });
    }
};
