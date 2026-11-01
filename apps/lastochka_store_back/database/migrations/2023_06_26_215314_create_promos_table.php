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
    Schema::create('promos', function (Blueprint $table) {
      $table->id();
      $table->string('title');
      $table->string('promo');
      $table->dateTime('start_date')->nullable();
      $table->dateTime('end_date')->nullable();
      $table->integer('percent');
      $table->integer('type_id');
      $table->boolean('reusable')->default(0);
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
    Schema::dropIfExists('promos');
  }
};
