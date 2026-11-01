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
    Schema::table('assemblies', function (Blueprint $table) {
      $table->timestamp('cooking_started_at')->nullable()->after('user_id');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('assemblies', function (Blueprint $table) {
      $table->dropColumn('cooking_started_at');
    });
  }
};
