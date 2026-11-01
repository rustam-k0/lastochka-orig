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
    Schema::create('join_order_supplements_values', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_item_supplement_id')->constrained();
      $table->foreignId('supplement_value_id')->constrained();
      $table->integer('amount')->nullable();
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::dropIfExists('join_order_supplements_values');
  }
};
