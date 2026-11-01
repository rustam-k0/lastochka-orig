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
    Schema::table('products', function (Blueprint $table) {
      $table->integer('info_weight')->nullable()->after('weight');
      $table->integer('info_weight_type_id')->nullable()->after('info_weight');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('products', function (Blueprint $table) {
      $table->dropColumn('info_weight');
      $table->dropColumn('info_weight_type_id');
    });
  }
};
