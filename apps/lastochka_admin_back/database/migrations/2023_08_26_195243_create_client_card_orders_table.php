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
    Schema::create('client_card_orders', function (Blueprint $table) {
      $table->id();
      $table->foreignId('client_id')->constrained();
      $table->string('payment_id');
      $table->timestamp('confirmed_at')->nullable();
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
    Schema::dropIfExists('client_card_orders');
  }
};
