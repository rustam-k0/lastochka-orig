<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrinterMenuItemTest extends TestCase
{
    use DatabaseTransactions;

    public function test_printers_menu_item_exists(): void
    {
        $item = DB::table('menu_items')->where('name', 'printers')->first();

        $this->assertNotNull($item, 'Пункт меню printers не найден — миграция add_printers_menu_item не применена');
        $this->assertSame('Принтеры печати', $item->title);
        $this->assertSame('printer-line', $item->icon);
        $this->assertEquals(1, $item->is_visible);
        $this->assertSame(['admin'], json_decode($item->accesses, true));
    }

    public function test_printers_menu_item_is_not_duplicated(): void
    {
        // Миграция идемпотентна: повторный прогон не должен добавить второй пункт.
        $count = DB::table('menu_items')->where('name', 'printers')->count();

        $this->assertSame(1, $count);
    }
}
