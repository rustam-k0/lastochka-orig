<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('id');
        });

        // Backfill: get shop_id from integration_configs where type=1c, default to 1
        $shopId = DB::table('integration_configs')->where('integration_type', '1c')->value('shop_id') ?? 1;
        DB::table('orders')->update(['shop_id' => $shopId]);

        // Make NOT NULL
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Drop UNIQUE on number if exists
        $indexes = collect(DB::select("SHOW INDEX FROM orders WHERE Column_name = 'number' AND Non_unique = 0"));
        if ($indexes->isNotEmpty()) {
            $indexName = $indexes->first()->Key_name;
            DB::statement("ALTER TABLE orders DROP INDEX `{$indexName}`");
        }

        // Create UNIQUE on (number, shop_id)
        DB::statement('ALTER TABLE orders ADD UNIQUE KEY `orders_number_shop_id_unique` (`number`, `shop_id`)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Drop composite unique
            $indexes = collect(DB::select("SHOW INDEX FROM orders WHERE Key_name = 'orders_number_shop_id_unique'"));
            if ($indexes->isNotEmpty()) {
                DB::statement('ALTER TABLE orders DROP INDEX `orders_number_shop_id_unique`');
            }

            // Restore unique on number
            DB::statement('ALTER TABLE orders ADD UNIQUE KEY `orders_number_unique` (`number`)');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shop_id');
        });
    }
};
