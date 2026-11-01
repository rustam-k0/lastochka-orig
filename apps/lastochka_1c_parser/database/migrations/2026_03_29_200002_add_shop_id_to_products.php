<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('id');
        });

        // Backfill: get shop_id from integration_configs where type=1c, default to 1
        $shopId = DB::table('integration_configs')->where('integration_type', '1c')->value('shop_id') ?? 1;
        DB::table('products')->update(['shop_id' => $shopId]);

        // Make NOT NULL
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Drop UNIQUE on external_id if exists
        $indexes = collect(DB::select("SHOW INDEX FROM products WHERE Column_name = 'external_id' AND Non_unique = 0"));
        if ($indexes->isNotEmpty()) {
            $indexName = $indexes->first()->Key_name;
            DB::statement("ALTER TABLE products DROP INDEX `{$indexName}`");
        }

        // Create UNIQUE on (external_id, shop_id)
        DB::statement('ALTER TABLE products ADD UNIQUE KEY `products_external_id_shop_id_unique` (`external_id`, `shop_id`)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Drop composite unique
            $indexes = collect(DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_external_id_shop_id_unique'"));
            if ($indexes->isNotEmpty()) {
                DB::statement('ALTER TABLE products DROP INDEX `products_external_id_shop_id_unique`');
            }

            // Restore unique on external_id
            DB::statement('ALTER TABLE products ADD UNIQUE KEY `products_external_id_unique` (`external_id`)');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shop_id');
        });
    }
};
