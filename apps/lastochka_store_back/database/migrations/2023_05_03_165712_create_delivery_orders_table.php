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
    Schema::create('delivery_orders', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_id')->constrained();
      $table->foreignId('delivery_id')->constrained();
      $table->integer('status_id');
      $table->integer('priority')->default(0);
      $table->timestamp('started_at')->nullable();
      $table->timestamp('finished_at')->nullable();
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
    Schema::dropIfExists('delivery_orders');
  }
};
