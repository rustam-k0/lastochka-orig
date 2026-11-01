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
    Schema::table('shops', function (Blueprint $table) {
      $table->foreignId('sphere_id')->nullable()->after('title')->constrained();
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('shops', function (Blueprint $table) {
      $table->dropConstrainedForeignId('sphere_id');
    });
  }
};
