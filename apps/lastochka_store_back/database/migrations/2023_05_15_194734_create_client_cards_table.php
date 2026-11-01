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
    Schema::create('client_cards', function (Blueprint $table) {
      $table->id();
      $table->foreignId('client_id')->constrained();
      $table->string('binding_id');
      $table->string('masked_pan');
      $table->string('expiry_date');
      $table->boolean('selected')->default(0);
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
    Schema::dropIfExists('client_cards');
  }
};
