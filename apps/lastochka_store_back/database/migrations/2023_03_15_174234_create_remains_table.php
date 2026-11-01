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
    Schema::create('remains', function (Blueprint $table) {
      $table->id();
      $table->float('quantity',unsigned: true);
      $table->foreignId('product_id')->constrained('products');
      $table->foreignId('shop_id')->constrained('shops');
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
    Schema::dropIfExists('remains');
  }
};
