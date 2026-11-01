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
    if (Schema::hasColumn('clients', 'card_number')) {
      return;
    }
    Schema::table('clients', function (Blueprint $table) {
      $table->string('card_number', 16)->nullable()->after('loyalty_id');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    if (!Schema::hasColumn('clients', 'card_number')) {
      return;
    }
    Schema::table('clients', function (Blueprint $table) {
      $table->dropColumn('card_number');
    });
  }
};
