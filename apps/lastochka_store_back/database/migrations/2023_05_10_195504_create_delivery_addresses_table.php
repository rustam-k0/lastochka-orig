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
    Schema::create('delivery_addresses', function (Blueprint $table) {
      $table->id();
      $table->foreignId('delivery_info_id')->constrained('client_delivery_infos');
      $table->string('address');
      $table->string('latitude');
      $table->string('longitude');
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
    Schema::dropIfExists('delivery_addresses');
  }
};
