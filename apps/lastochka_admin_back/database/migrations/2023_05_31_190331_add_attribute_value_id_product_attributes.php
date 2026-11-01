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
    Schema::table('product_attributes', function (Blueprint $table) {
      $table->foreignId('attribute_value_id')->nullable()->after('float_value')->constrained('attribute_values');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('product_attributes', function (Blueprint $table) {
      $table->dropColumn('attribute_value_id');

    });
  }
};
