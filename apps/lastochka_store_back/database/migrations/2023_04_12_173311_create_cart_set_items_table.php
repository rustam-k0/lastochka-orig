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
    Schema::create('cart_set_items', function (Blueprint $table) {
      $table->id();
      $table->foreignId('product_id')->nullable()->constrained();
      $table->foreignId('cart_item_id')->nullable()->constrained('cart_items');
      $table->integer('quantity')->default(1);
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
    Schema::dropIfExists('cart_set_items');
  }
};
