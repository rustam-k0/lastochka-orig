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
    if (Schema::hasColumn('clients', 'loyalty_id')) {
      return;
    }
    Schema::table('clients', function (Blueprint $table) {
      $table->string('loyalty_id', 64)->nullable()->after('gender_type_id');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    if (!Schema::hasColumn('clients', 'loyalty_id')) {
      return;
    }
    Schema::table('clients', function (Blueprint $table) {
      $table->dropColumn('loyalty_id');
    });
  }
};
