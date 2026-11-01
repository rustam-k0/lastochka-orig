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
    Schema::create('assembly_items', function (Blueprint $table) {
      $table->id();
      $table->foreignId('assembly_id')->constrained();
      $table->foreignId('order_item_id')->constrained();
      $table->integer('quantity')->nullable();
      $table->integer('price')->nullable();
      $table->foreignId('explain_reason_id')->nullable()->constrained();
      $table->integer('status_id');
      $table->integer('type_id')->nullable();
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
    Schema::dropIfExists('assembly_items');
  }
};
