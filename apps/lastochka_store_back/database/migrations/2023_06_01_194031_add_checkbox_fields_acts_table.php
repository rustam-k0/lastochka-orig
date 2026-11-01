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
    Schema::table('acts', function (Blueprint $table) {
      $table->text('description')->nullable()->after('footer');
      $table->boolean('for_customers')->default(0)->after('description');
      $table->boolean('company')->default(0)->after('for_customers');
      $table->boolean('partners')->default(0)->after('company');
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('acts', function (Blueprint $table) {
      $table->dropColumn('description');
      $table->dropColumn('for_customers');
      $table->dropColumn('company');
      $table->dropColumn('partners');
    });
  }
};
