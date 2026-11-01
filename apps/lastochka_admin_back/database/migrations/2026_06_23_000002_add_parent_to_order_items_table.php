<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::table('order_items', function (Blueprint $table) {
      $table->foreignId('parent_id')->nullable()->after('order_id')->constrained('order_items')->nullOnDelete();
      $table->foreignId('source_supplement_value_id')->nullable()->after('parent_id')->constrained('supplement_values')->nullOnDelete();
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('order_items', function (Blueprint $table) {
      $table->dropConstrainedForeignId('parent_id');
      $table->dropConstrainedForeignId('source_supplement_value_id');
    });
  }
};
