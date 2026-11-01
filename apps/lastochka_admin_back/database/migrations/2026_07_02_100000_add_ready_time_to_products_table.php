<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up()
  {
    Schema::table('products', function (Blueprint $table) {
      if (!Schema::hasColumn('products', 'ready_fixed_time')) {
        $table->time('ready_fixed_time')->nullable()->after('effective_fixed_stock');
      }
    });
  }

  public function down()
  {
    Schema::table('products', function (Blueprint $table) {
      if (Schema::hasColumn('products', 'ready_fixed_time')) {
        $table->dropColumn('ready_fixed_time');
      }
    });
  }
};
