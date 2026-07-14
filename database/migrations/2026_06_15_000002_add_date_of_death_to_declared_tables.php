<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_answers', function (Blueprint $table) {
            $table->date('date_of_death')->nullable()->after('date_of_birth');
        });

        Schema::table('user_declared_relatives', function (Blueprint $table) {
            $table->date('date_of_death')->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_answers', function (Blueprint $table) {
            $table->dropColumn('date_of_death');
        });

        Schema::table('user_declared_relatives', function (Blueprint $table) {
            $table->dropColumn('date_of_death');
        });
    }
};
