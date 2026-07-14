<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_sessions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['in_progress', 'matched', 'confirmed', 'rejected'])->default('in_progress');
            $table->foreignUuid('matched_family_uuid')->nullable()->constrained('families', 'uuid')->nullOnDelete();
            $table->decimal('top_match_score', 5, 4)->nullable();
            $table->json('match_candidates')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('onboarding_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('onboarding_session_uuid')->constrained('onboarding_sessions', 'uuid')->cascadeOnDelete();
            $table->enum('relative_slot', [
                'self', 'father', 'mother',
                'paternal_grandfather', 'paternal_grandmother',
                'maternal_grandfather', 'maternal_grandmother',
                'other_relative',
            ]);
            $table->json('relation_hint')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('maiden_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('birthplace')->nullable();
            $table->boolean('is_living')->default(true);
            $table->timestamps();

            $table->unique(['onboarding_session_uuid', 'relative_slot'], 'onboarding_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_answers');
        Schema::dropIfExists('onboarding_sessions');
    }
};
