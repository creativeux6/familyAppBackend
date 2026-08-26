<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE media_files MODIFY COLUMN status ENUM('pending_upload', 'finalizing', 'active', 'deleted') NOT NULL DEFAULT 'pending_upload'");
        } else {
            // SQLite / others: enum is a string check; no ALTER needed beyond app validation.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE media_files MODIFY COLUMN status ENUM('pending_upload', 'active', 'deleted') NOT NULL DEFAULT 'pending_upload'");
        }
    }
};
