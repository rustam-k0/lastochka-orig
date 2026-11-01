<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Models\Address;
use App\Models\BonusHold;
use App\Models\BonusTransaction;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Проверяет, что при создании заказа с bonusAction=spend
 * баллы РЕЗЕРВИРУЮТСЯ (hold), а не сразу списываются с баланса.
 *
 * Тест гонит полный путь через CreateOrderAction — тот же,
 * что использует мобильный клиент через POST /api/v1/orders.
 */
final class CreateOrderReservesTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_create_reserves_bonus_without_debit(): void
    {
        // 1. Пользователь с балансом
        $user = User::factory()->create([
            'bonus_balance' => 1000,
            'bonus_reserved' => 0,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        // 2. Магазин с включёнными бонусами
        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => true,
            'bonus_spend_percent' => 50,
        ]);

        // 3. Товар в категории магазина
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'sku' => 'SKU-BONUS-RESERVE',
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        // 4. Корзина: 2 единицы × 300 = subtotal 600
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        // 5. Создаём заказ через реальный CreateOrderAction с bonusAction=spend
        // eligible_amount = 600, bonus_spend_percent = 50 → maxAllowed = 300
        // requested = 200 → bonus_spent = min(200, 300) = 200
        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
            bonusAction: 'spend',
            bonusSpendAmount: 200,
        ));

        $order = Order::findOrFail($result['orderId']);
        $bonusSpent = (int) $order->bonus_spent;

        // 6. Баланс НЕ изменился (баллы не списаны при создании)
        $this->assertSame(1000, $user->fresh()->bonus_balance, 'Баланс не должен уменьшаться при создании заказа');

        // 7. bonus_reserved пополнен на сумму резерва
        $this->assertSame($bonusSpent, $user->fresh()->bonus_reserved, 'bonus_reserved должен равняться bonus_spent заказа');

        // 8. Создана одна held-запись BonusHold с правильной суммой
        $hold = BonusHold::where('order_id', $order->id)
            ->where('status', BonusHold::STATUS_HELD)
            ->first();
        $this->assertNotNull($hold, 'Должна существовать held-запись BonusHold для заказа');
        $this->assertSame($bonusSpent, $hold->amount, 'Сумма hold должна совпадать с bonus_spent заказа');

        // 9. Транзакция списания НЕ создана — списание будет при сборке
        $spendCount = BonusTransaction::where('user_id', $user->id)
            ->where('type', 'spend')
            ->count();
        $this->assertSame(0, $spendCount, 'bonus_transactions с type=spend не должно создаваться при оформлении заказа');
    }
}
