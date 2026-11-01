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
    Schema::create('prices', function (Blueprint $table) {
      $table->id();
      $table->foreignId('product_id')->constrained('products');
      $table->integer('type_id');
      $table->foreignId('shop_id')->constrained();
      $table->integer('value',unsigned: true);
      $table->integer('step',unsigned: true);
      $table->boolean('blocked')->default(0);
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
    Schema::dropIfExists('prices');
  }
};
