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
    Schema::create('used_promos', function (Blueprint $table) {
      $table->id();
      $table->foreignIdFor(\App\Models\Client::class)->constrained();
      $table->foreignId('promo_id')->constrained();
      $table->foreignId('order_item_id')->constrained();
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
    Schema::dropIfExists('used_promos');
  }
};
