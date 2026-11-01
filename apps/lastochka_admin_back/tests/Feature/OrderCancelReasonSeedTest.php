<?php

namespace Tests\Feature;

use App\Models\OrderCancelReason;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderCancelReasonSeedTest extends TestCase
{
    use DatabaseTransactions;

    public function test_migration_seeds_seven_default_reasons(): void
    {
        $this->assertGreaterThanOrEqual(7, OrderCancelReason::count());

        $defaultTitles = [
            'Нет товара в наличии',
            'Клиент передумал / отказался',
            'Не удалось связаться с клиентом',
            'Ошибка оператора при оформлении',
            'Дубликат заказа',
            'Не подтверждён возраст/документ (ЕГАИС)',
            'Мошеннические действия',
        ];

        foreach ($defaultTitles as $title) {
            $this->assertTrue(
                OrderCancelReason::where('title', $title)->exists(),
                "Default reason '{$title}' not found in database"
            );
        }
    }

    public function test_scope_active_excludes_inactive_reasons(): void
    {
        $reason = OrderCancelReason::first();
        $reason->update(['is_active' => false]);

        $this->assertFalse(OrderCancelReason::active()->pluck('id')->contains($reason->id));
    }
}
