<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LASTOCHKI-JAMI-12190: миграция структурированных характеристик карточки
 * товара (Тип продукта, Страна, Форма выпуска, Производитель) — системные
 * строковые атрибуты группы «Параметры» + строки в default_attributes.
 */
class ProductCharacteristicAttributesMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const CODES = ['product_type', 'country', 'release_form', 'manufacturer'];

    private const MIGRATION = 'database/migrations/2026_07_14_120000_add_product_characteristic_attributes.php';

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    public function test_creates_four_characteristic_attributes_with_default_rows(): void
    {
        $this->runMigration();

        foreach (self::CODES as $code) {
            $attribute = DB::table('attributes')
                ->where('code', $code)
                ->whereNull('deleted_at')
                ->first();

            $this->assertNotNull($attribute, "Атрибут {$code} должен существовать");
            $this->assertSame(1, (int) $attribute->type_id, "{$code}: тип — строка");
            $this->assertSame(101, (int) $attribute->category_type_id, "{$code}: группа «Параметры»");
            $this->assertTrue(
                DB::table('default_attributes')->where('attribute_id', $attribute->id)->exists(),
                "{$code}: без строки в default_attributes атрибут не виден в карточках"
            );
        }
    }

    public function test_migration_is_idempotent(): void
    {
        $this->runMigration();
        $this->runMigration();

        $count = DB::table('attributes')
            ->whereIn('code', self::CODES)
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame(4, $count);
    }
}
