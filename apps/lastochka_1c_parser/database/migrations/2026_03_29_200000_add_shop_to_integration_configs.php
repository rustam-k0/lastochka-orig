<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('integration_type');
            $table->string('shop_code', 100)->nullable()->after('shop_id');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Add generated column shop_id_or_zero
        DB::statement('ALTER TABLE integration_configs ADD COLUMN shop_id_or_zero INT GENERATED ALWAYS AS (IFNULL(shop_id, 0)) STORED AFTER shop_code');

        // Drop old unique on integration_type
        $indexes = collect(DB::select("SHOW INDEX FROM integration_configs WHERE Column_name = 'integration_type' AND Non_unique = 0"));
        if ($indexes->isNotEmpty()) {
            $indexName = $indexes->first()->Key_name;
            DB::statement("ALTER TABLE integration_configs DROP INDEX `{$indexName}`");
        }

        // Create new unique on (integration_type, shop_id_or_zero)
        DB::statement('ALTER TABLE integration_configs ADD UNIQUE KEY `integration_configs_type_shop_unique` (`integration_type`, `shop_id_or_zero`)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Restore unique on integration_type
            $indexes = collect(DB::select("SHOW INDEX FROM integration_configs WHERE Key_name = 'integration_configs_type_shop_unique'"));
            if ($indexes->isNotEmpty()) {
                DB::statement('ALTER TABLE integration_configs DROP INDEX `integration_configs_type_shop_unique`');
            }

            DB::statement('ALTER TABLE integration_configs DROP COLUMN shop_id_or_zero');
        }

        Schema::table('integration_configs', function (Blueprint $table) {
            $table->dropColumn(['shop_code', 'shop_id']);
            $table->string('integration_type', 50)->unique()->change();
        });
    }
};
