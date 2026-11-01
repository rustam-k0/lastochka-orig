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
    Schema::create('shop_zones', function (Blueprint $table) {
      $table->id();
      $table->foreignId('shop_id')->constrained();
      $table->integer('type_id');
      $table->integer('delivery_price');
      $table->integer('min_order_price');
      $table->softDeletes();
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::dropIfExists('shop_zones');
  }
};
