<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::table('order_items', function (Blueprint $table) {
      $table->foreignId('origin_product_id')->after('product_id')->nullable()->constrained('products');
      $table->integer('origin_quantity')->after('origin_product_id')->nullable();
      $table->float('origin_price')->after('origin_product_id')->nullable();
    });

    Schema::table('orders', function (Blueprint $table) {
      $table->float('origin_amount')->nullable()->after('amount');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('order_items', function (Blueprint $table) {
      $table->dropConstrainedForeignId('origin_product_id');
      $table->dropColumn('origin_quantity');
      $table->dropColumn('origin_price');
    });

    Schema::table('orders', function (Blueprint $table) {
      $table->dropColumn('origin_amount');
    });
  }
};
