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
      $table->string('entrance')->nullable()->after('first_order');
      $table->string('intercom')->nullable()->after('entrance');
      $table->string('floor')->nullable()->after('intercom');
      $table->string('flat')->nullable()->after('floor');
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
      $table->dropColumn('entrance');
      $table->dropColumn('intercom');
      $table->dropColumn('floor');
      $table->dropColumn('flat');
    });
  }
};
