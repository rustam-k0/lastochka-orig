<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VacancyMenuItemTest extends TestCase
{
    use DatabaseTransactions;

    public function test_vacancies_menu_item_exists(): void
    {
        $item = DB::table('menu_items')->where('name', 'vacancies')->first();

        $this->assertNotNull($item, 'Пункт меню vacancies не найден — миграция add_vacancies_menu_item не применена');
        $this->assertSame('Работа в компании', $item->title);
        $this->assertSame('briefcase-line', $item->icon);
        $this->assertEquals(1, $item->is_visible);
        $this->assertSame(['admin'], json_decode($item->accesses, true));
    }
}
