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
            $table->string('billing_period', 16)->default('monthly')->after('currency');
        });

        // Free plan renews yearly; all others monthly.
        DB::table('storage_plans')->where('slug', 'free')->update(['billing_period' => 'yearly']);
        DB::table('storage_plans')->where('slug', '!=', 'free')->update(['billing_period' => 'monthly']);

        // Backfill open-ended assignments so every plan has a renewal date.
        $assignments = DB::table('user_plan_assignments')
            ->whereNull('ends_at')
            ->where('is_active', true)
            ->get(['id', 'storage_plan_uuid', 'starts_at']);

        foreach ($assignments as $row) {
            $period = DB::table('storage_plans')
                ->where('uuid', $row->storage_plan_uuid)
                ->value('billing_period') ?: 'monthly';

            $startsAt = $row->starts_at ? \Carbon\Carbon::parse($row->starts_at) : now();
            $endsAt = $period === 'yearly'
                ? $startsAt->copy()->addYear()
                : $startsAt->copy()->addMonth();

            // If start was long ago, chain periods until ends_at is in the future.
            while ($endsAt->lte(now())) {
                $endsAt = $period === 'yearly'
                    ? $endsAt->copy()->addYear()
                    : $endsAt->copy()->addMonth();
            }

            DB::table('user_plan_assignments')->where('id', $row->id)->update([
                'ends_at' => $endsAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('storage_plans', function (Blueprint $table) {
            $table->dropColumn('billing_period');
        });
    }
};
