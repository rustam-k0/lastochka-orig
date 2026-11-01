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
    Schema::table('prices', function (Blueprint $table) {
      $table->integer('price_without_discount')->nullable()->after('value');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('prices', function (Blueprint $table) {
      $table->dropColumn('price_without_discount');
    });
  }
};
