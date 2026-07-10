<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends media_events into a foundation for v2 event management
 * (expenses, bookings, agenda, etc.). Mobile v1 only uses events as
 * media folders; management tables are unused until v2 is enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_events', function (Blueprint $table) {
            $table->string('event_type', 64)->default('general')->after('location');
            $table->string('status', 32)->default('draft')->after('event_type');
            $table->timestamp('starts_at')->nullable()->after('status');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->string('timezone', 64)->nullable()->after('ends_at');
            $table->string('currency', 3)->default('USD')->after('timezone');
            $table->boolean('management_enabled')->default(false)->after('currency');
            $table->json('management_meta')->nullable()->after('management_enabled');
            $table->text('notes')->nullable()->after('management_meta');

            $table->index(['owner_user_id', 'event_type', 'status']);
        });

        Schema::create('event_expenses', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('media_event_uuid')->constrained('media_events', 'uuid')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 64)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('USD');
            $table->date('spent_on')->nullable();
            $table->string('paid_by_name')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('recorded');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['media_event_uuid', 'category', 'status']);
        });

        Schema::create('event_bookings', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('media_event_uuid')->constrained('media_events', 'uuid')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('booking_type', 64)->default('other');
            $table->string('vendor_name')->nullable();
            $table->string('confirmation_code')->nullable();
            $table->string('status', 32)->default('planned');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->decimal('cost_amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('location')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['media_event_uuid', 'booking_type', 'status']);
        });

        Schema::create('event_tasks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('media_event_uuid')->constrained('media_events', 'uuid')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('todo');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['media_event_uuid', 'status', 'due_at']);
        });

        Schema::create('event_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('media_event_uuid')->constrained('media_events', 'uuid')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('viewer');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['media_event_uuid', 'user_id']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_collaborators');
        Schema::dropIfExists('event_tasks');
        Schema::dropIfExists('event_bookings');
        Schema::dropIfExists('event_expenses');

        Schema::table('media_events', function (Blueprint $table) {
            $table->dropIndex(['owner_user_id', 'event_type', 'status']);
            $table->dropColumn([
                'event_type',
                'status',
                'starts_at',
                'ends_at',
                'timezone',
                'currency',
                'management_enabled',
                'management_meta',
                'notes',
            ]);
        });
    }
};
