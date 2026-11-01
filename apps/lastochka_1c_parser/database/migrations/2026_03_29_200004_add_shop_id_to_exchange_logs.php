<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('id');
        });

        // Backfill: get shop_id from integration_configs where type=1c, default to 1
        $shopId = DB::table('integration_configs')->where('integration_type', '1c')->value('shop_id') ?? 1;
        DB::table('exchange_logs')->update(['shop_id' => $shopId]);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Add INDEX on (shop_id, type)
        DB::statement('ALTER TABLE exchange_logs ADD INDEX `exchange_logs_shop_id_type_index` (`shop_id`, `type`)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Drop index
            $indexes = collect(DB::select("SHOW INDEX FROM exchange_logs WHERE Key_name = 'exchange_logs_shop_id_type_index'"));
            if ($indexes->isNotEmpty()) {
                DB::statement('ALTER TABLE exchange_logs DROP INDEX `exchange_logs_shop_id_type_index`');
            }
        }

        Schema::table('exchange_logs', function (Blueprint $table) {
            $table->dropColumn('shop_id');
        });
    }
};
