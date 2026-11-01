<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImgTable extends Migration
{
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::create("img", function (Blueprint $table) {
      $table->id();
      $table->string("path", 500);
      $table->string("origin_name")->nullable();
      $table->string("alt")->nullable();
      $table->string("size")->nullable();
      $table->string("type")->nullable();
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
    Schema::dropIfExists("img");
  }
}
