<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_contact_hashes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('phone_hash', 64);
            $table->timestamps();

            $table->unique(['user_id', 'phone_hash']);
            $table->index('phone_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contact_hashes');
    }
};
