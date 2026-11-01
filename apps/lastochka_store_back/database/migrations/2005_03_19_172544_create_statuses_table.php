<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStatusesTable extends Migration
{
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::create("statuses", function (Blueprint $table) {
      $table->id();
      $table->string("name");
      $table->string("route")->nullable(); // Роут для фронта
      $table->string("type")->nullable(); // Русское название сущности
      $table->integer("code");
      $table->string("table_name");
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
    Schema::dropIfExists("status");
  }
}
