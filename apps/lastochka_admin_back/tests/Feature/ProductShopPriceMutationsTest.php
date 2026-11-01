<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductShopPriceMutationsTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  protected User $user;
  protected Shop $shop;
  protected Product $product;

  protected function setUp(): void
  {
    parent::setUp();

    Cache::forever(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY, true);

    $this->user = $this->adminUser();

    $this->shop = Shop::create([
      'title' => 'Test Shop ' . uniqid(),
      'code' => 'test-shop-' . uniqid(),
      'id_1c' => 0,
      'default' => false,
      'slot_max_orders' => 10,
      'slot_time' => 30,
      'payment_type_id' => 1,
      'is_popular' => false,
    ]);

    $this->product = Product::create([
      'title' => 'Test Product ' . uniqid(),
      'shop_id' => $this->shop->id,
      'unit_type_id' => 1,
      'status_id' => 1,
      'fill_percent' => 0,
      'publish_after_unlock' => false,
    ]);
  }

  protected function tearDown(): void
  {
    Cache::forget(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY);
    parent::tearDown();
  }

  public function test_save_mutation_creates_offer(): void
  {
    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($product_id: Int!, $shop_id: Int!, $price: Float!, $quantity: Int!) {
        ProductShopPriceSave(product_id: $product_id, shop_id: $shop_id, price: $price, quantity: $quantity) {
          value
          shop_id
        }
      }
    ', [
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'price' => 320.5,
      'quantity' => 7,
    ]);

    $response->assertJson([
      'data' => ['ProductShopPriceSave' => ['value' => 320.5, 'shop_id' => $this->shop->id]],
    ]);

    $this->assertDatabaseHas('remains', [
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'quantity' => 7,
    ]);

    $this->assertSame(1, EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'price_updated')->count());
    $this->assertSame(1, EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'stock_updated')->count());
  }

  public function test_save_mutation_rejects_non_positive_price(): void
  {
    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($product_id: Int!, $shop_id: Int!, $price: Float!, $quantity: Int!) {
        ProductShopPriceSave(product_id: $product_id, shop_id: $shop_id, price: $price, quantity: $quantity) {
          value
        }
      }
    ', [
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'price' => 0,
      'quantity' => 7,
    ]);

    $response->assertGraphQLErrorMessage('Validation failed for the field [ProductShopPriceSave].');
  }

  public function test_save_mutation_fails_when_network_catalog_disabled(): void
  {
    Cache::forever(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY, false);

    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($product_id: Int!, $shop_id: Int!, $price: Float!, $quantity: Int!) {
        ProductShopPriceSave(product_id: $product_id, shop_id: $shop_id, price: $price, quantity: $quantity) {
          value
        }
      }
    ', [
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'price' => 10,
      'quantity' => 1,
    ]);

    $this->assertNotEmpty($response->json('errors'));
  }

  public function test_delete_mutation_removes_offer_and_publishes_zeroes(): void
  {
    Price::create([
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 100,
      'step' => 1,
    ]);
    Remain::create([
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'quantity' => 5,
    ]);

    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($product_id: Int!, $shop_id: Int!) {
        ProductShopPriceDelete(product_id: $product_id, shop_id: $shop_id)
      }
    ', [
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
    ]);

    $response->assertJson(['data' => ['ProductShopPriceDelete' => true]]);

    // Строки soft-deleted
    $this->assertSame(0, Price::where('product_id', $this->product->id)
      ->where('shop_id', $this->shop->id)->count());
    $this->assertSame(0, Remain::where('product_id', $this->product->id)
      ->where('shop_id', $this->shop->id)->count());

    // События с нулями — оффер на витрине деактивируется (price > 0 обязателен)
    $priceEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'price_updated')->latest('id')->first();
    $this->assertSame(0.0, (float) $priceEvent->payload['price']);

    $stockEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'stock_updated')->latest('id')->first();
    $this->assertSame(0, (int) $stockEvent->payload['quantity']);
  }

  public function test_delete_mutation_is_idempotent(): void
  {
    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($product_id: Int!, $shop_id: Int!) {
        ProductShopPriceDelete(product_id: $product_id, shop_id: $shop_id)
      }
    ', [
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
    ]);

    $response->assertJson(['data' => ['ProductShopPriceDelete' => true]]);
  }

  public function test_delete_mutation_works_for_soft_deleted_shop(): void
  {
    Price::create([
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 100,
      'step' => 1,
    ]);
    Remain::create([
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'quantity' => 5,
    ]);

    $this->shop->delete();

    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($product_id: Int!, $shop_id: Int!) {
        ProductShopPriceDelete(product_id: $product_id, shop_id: $shop_id)
      }
    ', [
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
    ]);

    $response->assertJson(['data' => ['ProductShopPriceDelete' => true]]);

    // События с нулями — оффер на витрине деактивируется (price > 0 обязателен)
    $priceEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'price_updated')->latest('id')->first();
    $this->assertSame(0.0, (float) $priceEvent->payload['price']);

    $stockEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'stock_updated')->latest('id')->first();
    $this->assertSame(0, (int) $stockEvent->payload['quantity']);
  }

  public function test_apply_to_all_covers_active_shops(): void
  {
    $shop2 = Shop::create([
      'title' => 'Test Shop 2 ' . uniqid(),
      'code' => 'test-shop2-' . uniqid(),
      'id_1c' => 0,
      'default' => false,
      'slot_max_orders' => 10,
      'slot_time' => 30,
      'payment_type_id' => 1,
      'is_popular' => false,
    ]);

    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($product_id: Int!, $price: Float!, $quantity: Int!) {
        ProductShopPricesApplyToAll(product_id: $product_id, price: $price, quantity: $quantity)
      }
    ', [
      'product_id' => $this->product->id,
      'price' => 99.9,
      'quantity' => 3,
    ]);

    $processed = $response->json('data.ProductShopPricesApplyToAll');
    // Затронуты как минимум оба тестовых магазина; точное число зависит от
    // наполнения общей тестовой БД
    $this->assertGreaterThanOrEqual(2, $processed);

    foreach ([$this->shop, $shop2] as $shop) {
      $this->assertDatabaseHas('remains', [
        'product_id' => $this->product->id,
        'shop_id' => $shop->id,
        'quantity' => 3,
      ]);
      $this->assertSame(1, EventOutbox::where('entity_type', 'products')
        ->where('entity_id', $this->product->id)
        ->where('event_type', 'price_updated')
        ->where('payload->shop_code', $shop->code)
        ->count());
    }
  }

  public function test_product_create_with_price_publishes_anchor_offer_events(): void
  {
    // Регресс MID-226: карточка ProductCreate/ProductUpdate не несёт цену/
    // остаток оффера в сетевом каталоге — витрина строит офферы только из
    // пер-магазинных price_updated/stock_updated. Поле схемы для единицы
    // измерения — info_weight_type_id (обязательно при создании товара).
    $response = $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
      mutation ($title: String!, $shop_id: Int, $price: Float, $quantity: Int, $info_weight_type_id: Int) {
        ProductCreate(title: $title, shop_id: $shop_id, price: $price, quantity: $quantity, info_weight_type_id: $info_weight_type_id) {
          code
        }
      }
    ', [
      'title' => 'MID-226 offer test ' . uniqid(),
      'shop_id' => $this->shop->id,
      'price' => 300,
      'quantity' => 10,
      'info_weight_type_id' => 1,
    ]);

    $response->assertJson(['data' => ['ProductCreate' => ['code' => 200]]]);

    $productId = Product::where('title', 'like', 'MID-226 offer test%')
      ->latest('id')->value('id');

    $priceEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $productId)
      ->where('event_type', 'price_updated')->latest('id')->first();
    $this->assertNotNull($priceEvent, 'ProductCreate с ценой обязан публиковать офферное событие (MID-226)');
    $this->assertSame(300.0, (float) $priceEvent->payload['price']);
    $this->assertSame($this->shop->code, $priceEvent->payload['shop_code']);

    $stockEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $productId)
      ->where('event_type', 'stock_updated')->latest('id')->first();
    $this->assertNotNull($stockEvent);
    $this->assertSame(10, (int) $stockEvent->payload['quantity']);
  }
}
