<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FaqMenuItemTest extends TestCase
{
    use DatabaseTransactions;

    public function test_faq_menu_item_exists(): void
    {
        $item = DB::table('menu_items')->where('name', 'faq')->first();

        $this->assertNotNull($item, 'Пункт меню faq не найден — миграция add_faq_menu_item не применена');
        $this->assertSame('Вопросы и ответы', $item->title);
        $this->assertSame('question-answer-line', $item->icon);
        $this->assertEquals(1, $item->is_visible);
        $this->assertSame(['admin'], json_decode($item->accesses, true));
    }
}
