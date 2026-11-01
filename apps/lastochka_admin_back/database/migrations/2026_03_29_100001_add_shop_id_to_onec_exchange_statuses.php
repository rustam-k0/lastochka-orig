<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onec_exchange_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('id');
            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
        });

        // Backfill: set shop_id to first shop's id for all existing rows
        $firstShopId = DB::table('shops')->orderBy('id')->value('id');
        if ($firstShopId !== null) {
            DB::table('onec_exchange_statuses')->update(['shop_id' => $firstShopId]);
        }

        Schema::table('onec_exchange_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
            $table->dropUnique(['data_type']);
            $table->unique(['shop_id', 'data_type']);
        });
    }

    public function down(): void
    {
        Schema::table('onec_exchange_statuses', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'data_type']);
            $table->dropForeign(['shop_id']);
            $table->dropColumn('shop_id');
            $table->string('data_type', 50)->unique()->change();
        });
    }
};
