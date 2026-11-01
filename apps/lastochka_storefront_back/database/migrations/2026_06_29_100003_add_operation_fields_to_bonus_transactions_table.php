<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonus_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('bonus_transactions', 'operation_id')) {
                $table->string('operation_id', 64)->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('bonus_transactions', 'source')) {
                $table->string('source', 16)->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bonus_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('bonus_transactions', 'operation_id')) {
                $table->dropUnique(['operation_id']);
                $table->dropColumn('operation_id');
            }
            if (Schema::hasColumn('bonus_transactions', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
