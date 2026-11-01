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
    Schema::create('attributes', function (Blueprint $table) {
      $table->id();
      $table->string('title');
      $table->integer('type_id');
      $table->integer('category_type_id');
      $table->integer('position')->default(0);
      $table->boolean('required')->default(0);
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
    Schema::dropIfExists('attributes');
  }
};
