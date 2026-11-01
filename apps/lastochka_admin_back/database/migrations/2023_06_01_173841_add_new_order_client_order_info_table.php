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
    Schema::table('client_order_infos', function (Blueprint $table) {
      $table->boolean('first_order')->default(0)->after('longitude');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('client_order_infos', function (Blueprint $table) {
      $table->dropColumn('first_order');
    });
  }
};
