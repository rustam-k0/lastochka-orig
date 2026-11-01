<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateJoinDocumentsTable extends Migration
{
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::create("join_documents", function (Blueprint $table) {
      $table->id();
      $table->integer("item_id")->nullable();
      $table->integer("document_id")->nullable();
      $table->string("table_name")->nullable();
      $table->integer("head_id")->nullable();
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
    Schema::dropIfExists("join_documents");
  }
}
