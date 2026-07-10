<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_edge_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('inverse_code')->nullable();
            $table->boolean('is_symmetric')->default(false);
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('relationship_edges', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('from_member_uuid')->constrained('family_members', 'uuid')->cascadeOnDelete();
            $table->foreignUuid('to_member_uuid')->constrained('family_members', 'uuid')->cascadeOnDelete();
            $table->foreignId('edge_type_id')->constrained('relationship_edge_types');
            $table->decimal('confidence', 5, 4)->default(1.0000);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['from_member_uuid', 'to_member_uuid', 'edge_type_id'], 'rel_edge_unique');
            $table->index(['from_member_uuid', 'edge_type_id']);
            $table->index(['to_member_uuid', 'edge_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_edges');
        Schema::dropIfExists('relationship_edge_types');
    }
};
