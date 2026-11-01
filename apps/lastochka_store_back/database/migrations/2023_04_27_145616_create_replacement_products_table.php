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
    Schema::create('replacement_products', function (Blueprint $table) {
      $table->id();
      $table->foreignId('product_id')->constrained();
      $table->foreignId('assembly_item_id')->constrained();
      $table->boolean('selected')->default(0);
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
    Schema::dropIfExists('replacement_products');
  }
};
