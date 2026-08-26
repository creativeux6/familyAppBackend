<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('storage_plans', 'storage_limit_bytes')) {
                $table->unsignedBigInteger('storage_limit_bytes')->nullable()->after('quota_bytes');
            }
            if (! Schema::hasColumn('storage_plans', 'monthly_access_limit_bytes')) {
                $table->unsignedBigInteger('monthly_access_limit_bytes')->default(0)->after('storage_limit_bytes');
            }
            if (! Schema::hasColumn('storage_plans', 'streaming_limit_bytes')) {
                $table->unsignedBigInteger('streaming_limit_bytes')->default(0);
            }
            if (! Schema::hasColumn('storage_plans', 'download_limit_bytes')) {
                $table->unsignedBigInteger('download_limit_bytes')->default(0);
            }
            if (! Schema::hasColumn('storage_plans', 'file_view_limit_bytes')) {
                $table->unsignedBigInteger('file_view_limit_bytes')->default(0);
            }
            if (! Schema::hasColumn('storage_plans', 'max_users')) {
                $table->unsignedInteger('max_users')->default(1);
            }
            if (! Schema::hasColumn('storage_plans', 'is_shared')) {
                $table->boolean('is_shared')->default(false);
            }
            if (! Schema::hasColumn('storage_plans', 'max_shared_members')) {
                $table->unsignedInteger('max_shared_members')->default(0);
            }
            if (! Schema::hasColumn('storage_plans', 'warning_percentage')) {
                $table->unsignedTinyInteger('warning_percentage')->default(80);
            }
            if (! Schema::hasColumn('storage_plans', 'soft_limit_percentage')) {
                $table->unsignedTinyInteger('soft_limit_percentage')->default(90);
            }
            if (! Schema::hasColumn('storage_plans', 'hard_limit_percentage')) {
                $table->unsignedTinyInteger('hard_limit_percentage')->default(100);
            }
        });

        foreach (DB::table('storage_plans')->get() as $plan) {
            $storage = (int) ($plan->storage_limit_bytes ?: $plan->quota_bytes);
            $access = (int) $plan->monthly_access_limit_bytes;
            if ($access < 1) {
                $access = $storage * 3;
            }
            DB::table('storage_plans')->where('uuid', $plan->uuid)->update([
                'storage_limit_bytes' => $storage,
                'quota_bytes' => $storage,
                'monthly_access_limit_bytes' => $access,
                'streaming_limit_bytes' => (int) $plan->streaming_limit_bytes > 0
                    ? $plan->streaming_limit_bytes
                    : $access,
                'download_limit_bytes' => (int) $plan->download_limit_bytes > 0
                    ? $plan->download_limit_bytes
                    : $storage,
                'file_view_limit_bytes' => (int) $plan->file_view_limit_bytes > 0
                    ? $plan->file_view_limit_bytes
                    : $access,
            ]);
        }

        $this->remapSlug('family', 'personal', 'Personal');
        $this->remapSlug('premium', 'plus', 'Plus');

        Schema::table('user_plan_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('user_plan_assignments', 'pending_storage_plan_uuid')) {
                $table->uuid('pending_storage_plan_uuid')->nullable()->after('storage_plan_uuid');
            }
            if (! Schema::hasColumn('user_plan_assignments', 'pending_change_at')) {
                $table->timestamp('pending_change_at')->nullable()->after('pending_storage_plan_uuid');
            }
            if (! Schema::hasColumn('user_plan_assignments', 'billing_status')) {
                $table->string('billing_status', 32)->default('active')->after('is_active');
            }
            if (! Schema::hasColumn('user_plan_assignments', 'last_payment_failed_at')) {
                $table->timestamp('last_payment_failed_at')->nullable();
            }
            if (! Schema::hasColumn('user_plan_assignments', 'payment_retry_count')) {
                $table->unsignedInteger('payment_retry_count')->default(0);
            }
            if (! Schema::hasColumn('user_plan_assignments', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable();
            }
        });

        if (Schema::hasColumn('user_plan_assignments', 'pending_storage_plan_uuid')) {
            Schema::table('user_plan_assignments', function (Blueprint $table) {
                try {
                    $table->foreign('pending_storage_plan_uuid')
                        ->references('uuid')
                        ->on('storage_plans')
                        ->nullOnDelete();
                } catch (\Throwable) {
                    // Index/FK may already exist.
                }
            });
        }

        if (! Schema::hasTable('plan_assignment_members')) {
            Schema::create('plan_assignment_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assignment_id')->constrained('user_plan_assignments')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 16)->default('member');
                $table->timestamp('period_start');
                $table->timestamps();

                $table->unique(['assignment_id', 'user_id', 'period_start'], 'plan_members_assignment_user_period');
                $table->index(['user_id', 'period_start']);
            });
        }

        if (! Schema::hasTable('user_storage_usage')) {
            Schema::create('user_storage_usage', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assignment_id')->constrained('user_plan_assignments')->cascadeOnDelete();
                $table->uuid('plan_uuid');
                $table->unsignedBigInteger('storage_used_bytes')->default(0);
                $table->unsignedBigInteger('monthly_access_bytes')->default(0);
                $table->unsignedBigInteger('streamed_bytes')->default(0);
                $table->unsignedBigInteger('downloaded_bytes')->default(0);
                $table->unsignedBigInteger('file_viewed_bytes')->default(0);
                $table->unsignedInteger('upload_requests')->default(0);
                $table->unsignedInteger('download_requests')->default(0);
                $table->unsignedInteger('stream_requests')->default(0);
                $table->unsignedInteger('file_view_requests')->default(0);
                $table->unsignedBigInteger('storage_limit_bytes')->default(0);
                $table->unsignedBigInteger('monthly_access_limit_bytes')->default(0);
                $table->unsignedBigInteger('streaming_limit_bytes')->default(0);
                $table->unsignedBigInteger('download_limit_bytes')->default(0);
                $table->unsignedBigInteger('file_view_limit_bytes')->default(0);
                $table->unsignedInteger('max_shared_members')->default(0);
                $table->boolean('is_shared')->default(false);
                $table->timestamp('period_start');
                $table->timestamp('period_end')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->unique(['assignment_id', 'period_start'], 'usage_assignment_period');
                $table->index(['closed_at', 'period_end']);
            });
        }

        if (! Schema::hasTable('storage_usage_logs')) {
            Schema::create('storage_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->uuid('media_file_uuid')->nullable();
                $table->foreignId('assignment_id')->constrained('user_plan_assignments')->cascadeOnDelete();
                $table->foreignId('user_storage_usage_id')->constrained('user_storage_usage')->cascadeOnDelete();
                $table->string('action', 32);
                $table->bigInteger('bytes_used');
                $table->string('provider', 64)->nullable();
                $table->string('operation_type', 32)->nullable();
                $table->timestamps();

                $table->index(['assignment_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }

        $this->backfillUsage();
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_usage_logs');
        Schema::dropIfExists('user_storage_usage');
        Schema::dropIfExists('plan_assignment_members');
    }

    private function remapSlug(string $from, string $to, string $name): void
    {
        $fromRow = DB::table('storage_plans')->where('slug', $from)->first();
        if (! $fromRow) {
            return;
        }

        $toRow = DB::table('storage_plans')->where('slug', $to)->first();
        if ($toRow) {
            DB::table('storage_plans')->where('uuid', $fromRow->uuid)->update(['is_active' => false]);

            return;
        }

        DB::table('storage_plans')->where('uuid', $fromRow->uuid)->update([
            'slug' => $to,
            'name' => $name,
        ]);
    }

    private function backfillUsage(): void
    {
        $now = now();
        $assignments = DB::table('user_plan_assignments')->where('is_active', true)->get();

        foreach ($assignments as $assignment) {
            $plan = DB::table('storage_plans')->where('uuid', $assignment->storage_plan_uuid)->first();
            if (! $plan) {
                continue;
            }

            $exists = DB::table('user_storage_usage')
                ->where('assignment_id', $assignment->id)
                ->whereNull('closed_at')
                ->exists();
            if ($exists) {
                continue;
            }

            $user = DB::table('users')->where('id', $assignment->user_id)->first();
            $stock = (int) ($user->storage_used_bytes ?? 0);
            $periodAccess = (int) ($user->storage_read_period_bytes ?? 0);
            $periodStart = $assignment->starts_at ?? $now;
            $storage = (int) ($plan->storage_limit_bytes ?: $plan->quota_bytes);
            $access = (int) ($plan->monthly_access_limit_bytes ?: $storage * 3);

            $usageId = DB::table('user_storage_usage')->insertGetId([
                'assignment_id' => $assignment->id,
                'plan_uuid' => $plan->uuid,
                'storage_used_bytes' => $stock,
                'monthly_access_bytes' => $periodAccess,
                'streamed_bytes' => 0,
                'downloaded_bytes' => $periodAccess,
                'file_viewed_bytes' => 0,
                'upload_requests' => 0,
                'download_requests' => 0,
                'stream_requests' => 0,
                'file_view_requests' => 0,
                'storage_limit_bytes' => $storage,
                'monthly_access_limit_bytes' => $access,
                'streaming_limit_bytes' => (int) ($plan->streaming_limit_bytes ?: $access),
                'download_limit_bytes' => (int) ($plan->download_limit_bytes ?: $storage),
                'file_view_limit_bytes' => (int) ($plan->file_view_limit_bytes ?: $access),
                'max_shared_members' => (int) ($plan->max_shared_members ?? 0),
                'is_shared' => (bool) ($plan->is_shared ?? false),
                'period_start' => $periodStart,
                'period_end' => $assignment->ends_at,
                'closed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            unset($usageId);

            $memberExists = DB::table('plan_assignment_members')
                ->where('assignment_id', $assignment->id)
                ->where('user_id', $assignment->user_id)
                ->where('period_start', $periodStart)
                ->exists();
            if (! $memberExists) {
                DB::table('plan_assignment_members')->insert([
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'role' => 'owner',
                    'period_start' => $periodStart,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
