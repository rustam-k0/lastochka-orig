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
      $table->integer('status_id')->after('payment_type_id')->nullable()->default(\App\Models\Status::CODE_ACTIVE);
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
      $table->dropColumn('status_id');
    });
  }
};
