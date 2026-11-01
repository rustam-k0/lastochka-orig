<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'promocode_code')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('promocode_code', 50)->nullable()->after('discount_percent');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('orders', 'promocode_code')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('promocode_code');
        });
    }
};
