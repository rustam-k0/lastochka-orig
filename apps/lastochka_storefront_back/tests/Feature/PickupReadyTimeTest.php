<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Pickup\PickupSlotService;
use App\Services\ReadyTime\ReadyTimeResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesProductReadyTimeModule;
use Tests\TestCase;

final class PickupReadyTimeTest extends TestCase
{
    use RefreshDatabase;
    use TogglesProductReadyTimeModule;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->invalidateProductReadyTimeCache();
        parent::tearDown();
    }

    public function test_slots_before_ready_from_are_filtered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $store = Store::factory()->create([
            'is_open_24_7' => true,
            'slot_duration' => 60,
        ]);

        $readyFrom = Carbon::parse('2026-07-02 12:00:00', config('app.timezone'));

        $slots = app(PickupSlotService::class)->getSlots($store, '2026-07-02', $readyFrom);

        $this->assertNotEmpty($slots);
        // Первый доступный слот начинается ровно в 12:00 (слот в момент готовности доступен)
        $this->assertSame($readyFrom->timestamp, $slots[0]['id']);
    }

    public function test_null_ready_from_keeps_previous_behaviour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $store = Store::factory()->create([
            'is_open_24_7' => true,
            'slot_duration' => 60,
        ]);

        $slots = app(PickupSlotService::class)->getSlots($store, '2026-07-02', null);

        // с 08:00 до 24:00 по часу — 16 слотов, первый в 08:00
        $this->assertSame(
            Carbon::parse('2026-07-02 08:00:00', config('app.timezone'))->timestamp,
            $slots[0]['id'],
        );
    }

    public function test_fixed_readiness_is_daily_and_cuts_future_date_mornings(): void
    {
        $this->setProductReadyTimeModule(true);
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $store = Store::factory()->create([
            'is_open_24_7' => true,
            'slot_duration' => 60,
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'quantity' => 100,
            'ready_fixed_time' => '12:00:00',
        ]);

        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        // Fixed-готовность ежедневная: на завтра constraint = завтра@12:00,
        // а не сегодняшний (уже «прошедший» для завтрашних слотов) момент.
        $tomorrow = Carbon::parse('2026-07-03 00:00:00', config('app.timezone'));
        $readyFrom = app(ReadyTimeResolver::class)->forUserStore($user, $store->id, $tomorrow);

        $this->assertSame(
            Carbon::parse('2026-07-03 12:00:00', config('app.timezone'))->toIso8601String(),
            $readyFrom->toIso8601String(),
        );

        // И утренние слоты завтрашнего дня реально отрезаются
        $slots = app(PickupSlotService::class)->getSlots($store, '2026-07-03', $readyFrom);
        $this->assertNotEmpty($slots);
        $this->assertSame($readyFrom->timestamp, $slots[0]['id']);
    }
}
