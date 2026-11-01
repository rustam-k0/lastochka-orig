<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::table('orders', function (Blueprint $table) {
      $table->foreignId('card_id')->nullable()->after('payment_type_id')->constrained('client_cards');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('orders', function (Blueprint $table) {
      $table->dropColumn('card_id');
    });
  }
};
