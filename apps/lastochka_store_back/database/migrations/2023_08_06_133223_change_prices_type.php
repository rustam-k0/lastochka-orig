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
      Schema::table('orders', function (Blueprint $table) {
        $table->float('amount')->nullable()->change();
      });

      Schema::table('order_items', function (Blueprint $table) {
        $table->float('price')->nullable()->change();
        $table->float('price_during_order')->nullable()->change();
      });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
