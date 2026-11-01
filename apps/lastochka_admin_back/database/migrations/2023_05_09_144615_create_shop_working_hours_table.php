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

    Schema::create('shop_working_hours', function (Blueprint $table) {
      $table->id();
      $table->foreignId('shop_id')->constrained();
      $table->integer('day_of_week');
      $table->time('opening_time')->nullable();
      $table->time('closing_time')->nullable();
      $table->integer('type_id');
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
    Schema::dropIfExists('shop_working_hours');
  }
};
