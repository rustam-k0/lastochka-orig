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
    Schema::create('order_item_supplements', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_item_id',)->constrained();
      $table->foreignId('supplement_id')->constrained();
      $table->integer('amount')->nullable();
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
    Schema::dropIfExists('order_item_supplements');
  }
};
