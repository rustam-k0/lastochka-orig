<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'orders_enabled')) {
                $table->boolean('orders_enabled')->default(true)->after('bonus_balance');
            }
            if (! Schema::hasColumn('users', 'promo_enabled')) {
                $table->boolean('promo_enabled')->default(true)->after('orders_enabled');
            }
            if (! Schema::hasColumn('users', 'push_enabled')) {
                $table->boolean('push_enabled')->default(true)->after('promo_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                ['orders_enabled', 'promo_enabled', 'push_enabled'],
                fn (string $col) => Schema::hasColumn('users', $col),
            );

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
