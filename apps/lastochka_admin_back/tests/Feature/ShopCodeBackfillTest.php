<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Регрессия: дефолтный магазин создаётся raw-insert'ом (DefaultValuesSeeder),
 * минуя Eloquent-хук Shop::creating → code остаётся NULL → URL обмена 1С теряет shop_code.
 *
 * Запускать ТОЛЬКО локально (phpunit.xml не переопределяет DB_* — см. ~/.claude/CLAUDE.md).
 */
class ShopCodeBackfillTest extends TestCase
{
    use DatabaseTransactions;

    public function test_raw_inserted_shop_starts_without_code(): void
    {
        // Воспроизводим путь DefaultValuesSeeder до фикса: raw insert минует хук creating.
        $id = DB::table('shops')->insertGetId([
            'title' => 'Тестовый магазин',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('shops')->where('id', $id)->value('code'));
    }

    public function test_backfill_migration_fills_null_codes(): void
    {
        // Уникальный title: миграция обходит все магазины с NULL-кодом стенда
        $title = 'Тестовый магазин ' . uniqid();

        $id = DB::table('shops')->insertGetId([
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Эталон считаем до миграции: после неё код уже занят этим же магазином,
        // и generateCodeFromTitle вернул бы следующий свободный («…-1»)
        $expected = Shop::generateCodeFromTitle($title);

        $migration = require database_path('migrations/2026_05_25_120000_backfill_shops_code.php');
        $migration->up();

        $code = DB::table('shops')->where('id', $id)->value('code');

        $this->assertNotNull($code);
        $this->assertNotSame('', $code);
        // Транслитерация + slug, как в Shop::generateCodeFromTitle.
        $this->assertSame($expected, $code);
    }

    public function test_backfill_leaves_shop_without_title_with_fallback_code(): void
    {
        $id = DB::table('shops')->insertGetId([
            'title' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_05_25_120000_backfill_shops_code.php');
        $migration->up();

        $this->assertSame('shop-' . $id, DB::table('shops')->where('id', $id)->value('code'));
    }
}
