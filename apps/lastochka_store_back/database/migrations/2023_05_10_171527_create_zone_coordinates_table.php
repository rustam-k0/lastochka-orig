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
    Schema::create('zone_coordinates', function (Blueprint $table) {
      $table->id();
      $table->foreignId('shop_zone_id')->constrained();
      $table->string('latitude');
      $table->string('longitude');
      $table->integer('position');
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
    Schema::dropIfExists('zone_coordinates');
  }
};
