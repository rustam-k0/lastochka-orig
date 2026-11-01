<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Обновляем существующие записи
        DB::table('orders')->where('status', 'new')->update(['status' => 'Новый']);
        DB::table('orders')->where('status', 'exported')->update(['status' => 'Новый']);
        DB::table('orders')->where('status', 'confirmed')->update(['status' => 'Новый']);
        DB::table('orders')->where('status', 'cancelled')->update(['status' => 'Отменен']);

        // Меняем default значение колонки
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 50)->default('Новый')->comment('Новый, НаСборке, Собран, ВДоставке, Доставлен, Отменен')->change();
        });
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'Новый')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'НаСборке')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'Собран')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'ВДоставке')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'Доставлен')->update(['status' => 'confirmed']);
        DB::table('orders')->where('status', 'Отменен')->update(['status' => 'cancelled']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 50)->default('new')->comment('new, exported, confirmed, cancelled')->change();
        });
    }
};
