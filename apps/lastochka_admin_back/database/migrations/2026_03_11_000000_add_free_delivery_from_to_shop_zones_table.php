<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shop_zones', function (Blueprint $table) {
            $table->integer('free_delivery_from')->nullable()->after('min_order_price');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shop_zones', function (Blueprint $table) {
            $table->dropColumn('free_delivery_from');
        });
    }
};
