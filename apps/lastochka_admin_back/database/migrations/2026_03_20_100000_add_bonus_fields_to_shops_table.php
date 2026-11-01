<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_bonus_enabled')->default(false)->after('ignore_stock');
            $table->unsignedTinyInteger('bonus_earn_percent')->default(0)->after('is_bonus_enabled');
            $table->unsignedTinyInteger('bonus_spend_percent')->default(0)->after('bonus_earn_percent');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['is_bonus_enabled', 'bonus_earn_percent', 'bonus_spend_percent']);
        });
    }
};
