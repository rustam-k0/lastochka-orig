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
    Schema::table('clients', function (Blueprint $table) {
      $table->string('google_id')->nullable()->after('id');
      $table->string('vkontakte_id')->nullable()->after('id');
      $table->string('apple_id')->nullable()->after('id');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('clients', function (Blueprint $table) {
      $table->dropColumn('google_id');
      $table->dropColumn('vkontakte_id');
      $table->dropColumn('apple_id');
    });
  }
};
