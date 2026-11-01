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
    Schema::create('reviews', function (Blueprint $table) {
      $table->id();
      $table->foreignId('client_id')->constrained();
      $table->morphs('reviewable');
      $table->integer('main_mark');
      $table->integer('delivery_mark')->nullable();
      $table->integer('assembly_mark')->nullable();
      $table->text('comment')->nullable();
      $table->integer('status_id');
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
    Schema::dropIfExists('reviews');
  }
};
