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
    Schema::create('client_order_infos', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_id')->constrained();
      $table->string('name');
      $table->string('surname')->nullable();
      $table->string('phone')->nullable();
      $table->string('email')->nullable();
      $table->string('address')->nullable();
      $table->string('latitude')->nullable();
      $table->string('longitude')->nullable();
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
    Schema::dropIfExists('client_order_infos');
  }
};
