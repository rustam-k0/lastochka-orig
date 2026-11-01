<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Лаг от момента заказа выпилен из фичи «время готовности» (LASTOCHKI-JAMI-11648):
// остаётся только ежедневное фиксированное время ready_fixed_time. Колонки
// ready_time_mode/ready_lag_minutes существуют лишь на средах, успевших накатить
// первоначальную версию миграции add_ready_time (dev) — здесь подчищаем.
return new class extends Migration
{
  public function up()
  {
    Schema::table('products', function (Blueprint $table) {
      $columns = array_filter(
        ['ready_time_mode', 'ready_lag_minutes'],
        fn (string $col) => Schema::hasColumn('products', $col),
      );

      if ($columns) {
        $table->dropColumn($columns);
      }
    });
  }

  public function down()
  {
    Schema::table('products', function (Blueprint $table) {
      if (!Schema::hasColumn('products', 'ready_time_mode')) {
        $table->string('ready_time_mode', 10)->nullable()->after('effective_fixed_stock');
      }
      if (!Schema::hasColumn('products', 'ready_lag_minutes')) {
        $table->unsignedInteger('ready_lag_minutes')->nullable()->after('ready_fixed_time');
      }
    });
  }
};
