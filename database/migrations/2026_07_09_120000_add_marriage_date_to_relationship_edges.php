<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relationship_edges', function (Blueprint $table) {
            $table->date('marriage_date')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('relationship_edges', function (Blueprint $table) {
            $table->dropColumn('marriage_date');
        });
    }
};
