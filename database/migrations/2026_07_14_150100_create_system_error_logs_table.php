<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_error_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 16)->nullable();
            $table->string('path', 512)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('exception_class', 255)->nullable();
            $table->text('message');
            $table->text('trace')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['status_code', 'occurred_at']);
            $table->index(['path', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_error_logs');
    }
};
