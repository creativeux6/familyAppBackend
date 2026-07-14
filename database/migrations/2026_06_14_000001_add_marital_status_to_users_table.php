<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'marital_status')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('marital_status', 20)->nullable()->after('is_anonymous');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'marital_status')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('marital_status');
        });
    }
};
