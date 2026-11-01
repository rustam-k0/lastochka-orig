<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\DeliverySystemClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Слоты доставки с учётом времени сборки корзины (LASTOCHKI-JAMI-11145).
 *
 * Эндпоинт delivery-slots должен передавать товары корзины пользователя
 * (по магазину) в slots-api — там по shops_categories.time_to_take
 * отсекаются слоты, на которые сборка не успевает.
 */
final class DeliverySlotsCartProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Store $store;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DeliveryClientInterface::class, DeliverySystemClient::class);

        config(['services.delivery_system.url' => 'https://delivery.test']);
        config(['services.delivery_system.api_key' => 'test-key']);

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create();
        $this->address = Address::factory()->for($this->user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);
    }

    private function slotsApiResponseBody(): array
    {
        return [
            'slots' => [
                ['start' => now()->addDay()->format('Y-m-d').' 10:00:00', 'end' => now()->addDay()->format('Y-m-d').' 12:00:00'],
            ],
            'min_order_price' => 500,
            'shipping_free_from' => 1500,
            'shipping_price' => 200,
        ];
    }

    private function fakeSlotsApiSuccess(): void
    {
        Http::fake(['delivery.test/*' => Http::response($this->slotsApiResponseBody())]);
    }

    private function addCartItem(Store $store, string $sku, int $quantity, int $step = 1): void
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $this->user->id]);

        $product = Product::factory()->for($store)->create([
            'sku' => $sku,
            'quantity_step' => $step,
            'measurement_unit' => $step > 1 ? 'gram' : 'piece',
            'quantity' => 100000,
        ]);

        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
        ]);
    }

    private function requestSlots(): void
    {
        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($this->user)
            ->getJson("/api/v1/stores/{$this->store->id}/delivery-slots?addressId={$this->address->id}&date={$date}")
            ->assertOk();
    }

    public function test_slots_request_includes_cart_products_of_requested_store_only(): void
    {
        $this->fakeSlotsApiSuccess();

        $otherStore = Store::factory()->create();

        $this->addCartItem($this->store, 'SKU-PIECE', 2);
        $this->addCartItem($this->store, 'SKU-WEIGHT', 1000, 500);
        $this->addCartItem($otherStore, 'SKU-OTHER', 1);

        $this->requestSlots();

        Http::assertSent(function ($request) {
            $products = collect($request->data()['products'] ?? [])->keyBy('articul');

            return $products->count() === 2
                && $products['SKU-PIECE']['count'] === 2
                && $products['SKU-WEIGHT']['count'] === 2
                && ! $products->has('SKU-OTHER');
        });
    }

    public function test_slots_request_without_cart_has_no_products(): void
    {
        $this->fakeSlotsApiSuccess();

        $this->requestSlots();

        Http::assertSent(fn ($request) => ! array_key_exists('products', $request->data()));
    }

    public function test_fallback_cache_is_scoped_to_cart_contents(): void
    {
        $date = now()->addDay()->format('Y-m-d');
        $url = "/api/v1/stores/{$this->store->id}/delivery-slots?addressId={$this->address->id}&date={$date}";

        // Первый запрос успешен, дальше slots-api недоступен — работает только fallback-кеш
        Http::fake([
            'delivery.test/*' => Http::sequence()
                ->push($this->slotsApiResponseBody())
                ->push(['error' => 'down'], 500)
                ->push(['error' => 'down'], 500),
        ]);

        $this->addCartItem($this->store, 'SKU-FIRST', 1);
        $this->requestSlots(); // успех → слоты закешированы под текущий состав корзины

        // Тот же состав корзины — fallback отдаёт кеш
        $this->actingAs($this->user)->getJson($url)->assertOk();

        // Состав изменился — кеша для него нет; отдать слоты чужого состава нельзя
        $this->addCartItem($this->store, 'SKU-SECOND', 1);
        $this->actingAs($this->user)->getJson($url)->assertStatus(503);
    }
}
