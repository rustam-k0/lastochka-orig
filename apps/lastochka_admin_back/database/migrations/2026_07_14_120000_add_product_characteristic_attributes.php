<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * LASTOCHKI-JAMI-12190: структурированные характеристики карточки товара.
     * Системные строковые атрибуты группы «Параметры» (category_type_id=101):
     * строка в default_attributes делает атрибут видимым во всех товарах,
     * значения пропагируются в storefront в массив attributes и рисуются
     * мобилкой в блоке «Характеристики». Бренд сюда намеренно не входит —
     * он ведётся отдельной сущностью Brand (модуль «Бренды»).
     */
    private const ATTRIBUTES = [
        ['title' => 'Тип продукта',  'code' => 'product_type', 'fill_percent' => 1],
        ['title' => 'Страна',        'code' => 'country',      'fill_percent' => 2],
        ['title' => 'Форма выпуска', 'code' => 'release_form', 'fill_percent' => 1],
        ['title' => 'Производитель', 'code' => 'manufacturer', 'fill_percent' => 1],
    ];

    public function up(): void
    {
        foreach (self::ATTRIBUTES as $attribute) {
            // Идемпотентность + защита UNIQUE-индекса attributes.code:
            // код занят (в т.ч. trashed-строкой) — не вставляем.
            $exists = DB::table('attributes')
                ->where('code', $attribute['code'])
                ->exists();
            if ($exists) {
                continue;
            }

            // В конец группы «Параметры», существующие позиции не сдвигаем.
            $position = (int) DB::table('attributes')
                ->where('category_type_id', 101)
                ->whereNull('deleted_at')
                ->max('position') + 1;

            $attributeId = DB::table('attributes')->insertGetId([
                'title' => $attribute['title'],
                'code' => $attribute['code'],
                'type_id' => 1, // Type::ATTRIBUTE_STRING
                'category_type_id' => 101, // Type::ATTRIBUTE_DEFAULT — группа «Параметры»
                'position' => $position,
                'required' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('default_attributes')->insert([
                'attribute_id' => $attributeId,
                'fill_percent' => $attribute['fill_percent'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('attributes')
            ->whereIn('code', array_column(self::ATTRIBUTES, 'code'))
            ->pluck('id');

        DB::table('default_attributes')->whereIn('attribute_id', $ids)->delete();
        DB::table('product_attributes')->whereIn('attribute_id', $ids)->delete();
        DB::table('attributes')->whereIn('id', $ids)->delete();
    }
};
