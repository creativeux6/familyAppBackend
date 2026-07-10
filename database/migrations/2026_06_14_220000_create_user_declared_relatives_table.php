<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_declared_relatives', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('relation_type');
            $table->unsignedSmallInteger('relation_index')->default(0);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('maiden_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('birthplace')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'unknown'])->default('unknown');
            $table->boolean('is_living')->default(true);
            $table->foreignUuid('member_uuid')->nullable()->constrained('family_members', 'uuid')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'relation_type', 'relation_index'], 'user_declared_relative_unique');
            $table->index(['last_name', 'first_name', 'date_of_birth']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_declared_relatives');
    }
};
