<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Type;
use App\Services\Export\OrderLabels;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Модуль «Оформление заказа без слотов» (checkout_without_slots, MID-256):
 * в Excel-экспорте заказов колонка слота пустая — иначе для заказов без слота
 * (в djami slot_start = slot_end = момент оформления по fallback консьюмера)
 * печаталось бы бессмысленное «18.08.2026, 15:02 — 15:02».
 * Самовывоз в модуль не входит: клиент выбирает время выдачи, слот реальный —
 * печатаем его и при включённом модуле.
 */
class OrderExportSlotWithoutSlotsModuleTest extends TestCase
{
    use DatabaseTransactions;

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['checkout_without_slots' => ['enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function orderWithSlot(int $typeId = Type::RECEIVE_DELIVERY): Order
    {
        $order = new Order();
        $order->type_id = $typeId;
        $order->slot_start_date = '2026-08-18 15:00:00';
        $order->slot_end_date = '2026-08-18 17:00:00';

        return $order;
    }

    /** @test */
    public function slot_label_is_rendered_when_module_disabled()
    {
        $this->setModule(false);

        $this->assertSame('18.08.2026, 15:00 — 17:00', (new OrderLabels())->slot($this->orderWithSlot()));
        $this->assertFalse(Order::isCheckoutWithoutSlotsModuleEnabled());
    }

    /** @test */
    public function slot_label_is_empty_when_module_enabled()
    {
        $this->setModule(true);

        $this->assertSame('', (new OrderLabels())->slot($this->orderWithSlot()));
        $this->assertTrue(Order::isCheckoutWithoutSlotsModuleEnabled());
    }

    /** @test */
    public function pickup_slot_label_is_rendered_when_module_enabled()
    {
        $this->setModule(true);

        $this->assertSame(
            '18.08.2026, 15:00 — 17:00',
            (new OrderLabels())->slot($this->orderWithSlot(Type::RECEIVE_PICKUP))
        );
    }
}
