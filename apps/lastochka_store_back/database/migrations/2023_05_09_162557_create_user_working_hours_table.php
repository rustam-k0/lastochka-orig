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
    Schema::create('user_working_hours', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_shop_id')->constrained('user_shops');
      $table->string('day_of_week');
      $table->time('opening_time');
      $table->time('closing_time');
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
    Schema::dropIfExists('user_working_hours');
  }
};
