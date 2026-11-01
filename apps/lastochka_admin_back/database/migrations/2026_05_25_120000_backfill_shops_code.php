<?php

use App\Models\Shop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Бэкфилл shops.code для магазинов с NULL-кодом.
 *
 * Дефолтный магазин создаётся в DefaultValuesSeeder через DB::table('shops')->insert(),
 * что минует Eloquent-событие creating → хук Shop::booted(), который генерирует code из title,
 * не срабатывает. В итоге у дефолтного магазина code = NULL, и URL обмена 1С
 * (config 1c_parser.url + '/1c-exchange/' + shop->code) рендерится без shop_code.
 *
 * Миграция дозаполняет code тем же алгоритмом, что и модель (slug + транслитерация + уникальность).
 * Запись через DB::table, чтобы не порождать RabbitMQ-события при миграции.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shops') || !Schema::hasColumn('shops', 'code')) {
            return;
        }

        DB::table('shops')
            ->whereNull('code')
            ->orderBy('id')
            ->get(['id', 'title'])
            ->each(function ($row) {
                $title = (string) ($row->title ?? '');

                $code = $title !== '' ? Shop::generateCodeFromTitle($title) : '';

                // Гарантируем непустой уникальный код даже для магазина без title.
                if ($code === '') {
                    $code = 'shop-' . $row->id;
                }

                DB::table('shops')->where('id', $row->id)->update(['code' => $code]);
            });
    }

    public function down(): void
    {
        // Необратимо: откат не обнуляет коды, т.к. они уже используются в настройках обмена 1С.
    }
};
