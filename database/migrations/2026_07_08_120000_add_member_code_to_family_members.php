<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->string('member_code', 12)->nullable()->after('uuid');
        });

        Schema::table('family_members', function (Blueprint $table) {
            $table->unique('member_code');
        });

        $existing = DB::table('family_members')->whereNull('member_code')->pluck('uuid');
        foreach ($existing as $uuid) {
            DB::table('family_members')
                ->where('uuid', $uuid)
                ->update(['member_code' => $this->uniqueCode()]);
        }
    }

    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropUnique(['member_code']);
            $table->dropColumn('member_code');
        });
    }

    private function uniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = 'M';
            for ($i = 0; $i < 7; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::table('family_members')->where('member_code', $code)->exists());

        return $code;
    }
};
