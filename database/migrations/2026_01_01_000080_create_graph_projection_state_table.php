<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graph_projection_state', function (Blueprint $table) {
            $table->string('entity_type');
            $table->string('entity_uuid');
            $table->enum('projection', ['neo4j'])->default('neo4j');
            $table->enum('status', ['pending', 'synced', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->primary(['entity_type', 'entity_uuid', 'projection']);
            $table->index(['status', 'projection']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_projection_state');
    }
};
