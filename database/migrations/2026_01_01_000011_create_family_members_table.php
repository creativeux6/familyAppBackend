<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('family_uuid')->constrained('families', 'uuid')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('maiden_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('date_of_death')->nullable();
            $table->string('birthplace')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'unknown'])->default('unknown');
            $table->boolean('is_living')->default(true);
            $table->boolean('is_anonymous')->default(false);
            $table->decimal('match_confidence', 5, 4)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['family_uuid', 'is_anonymous']);
            $table->index(['last_name', 'first_name', 'date_of_birth']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
