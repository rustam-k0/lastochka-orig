<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }

        DB::statement('ALTER TABLE shops MODIFY COLUMN payment_type_id INT NULL DEFAULT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }

        DB::statement("ALTER TABLE shops MODIFY COLUMN payment_type_id INT NOT NULL DEFAULT 1");
    }
};
