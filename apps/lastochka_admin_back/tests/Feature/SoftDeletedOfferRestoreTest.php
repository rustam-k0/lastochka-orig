<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Product\ProductUpdate;
use App\Models\AdminSetting;
use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Повторное заведение оффера после того, как товар убрали из магазина.
 *
 * `prices` и `remains` — soft-delete модели, но UNIQUE-индексы
 * (`prices_product_shop_type_unique`, `remains_product_shop_unique`) про
 * `deleted_at` не знают. Eloquent удалённую строку не видит и пытается
 * вставить новую — MySQL отвечает «Duplicate entry», сохранение товара падает
 * с Internal server error. Воспроизводится штатным сценарием: «убрать товар из
 * магазина», потом снова задать ему цену.
 */
class SoftDeletedOfferRestoreTest extends TestCase
{
  use DatabaseTransactions;

  protected function setUp(): void
  {
    (new ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered'))
      ->setValue(null, false);

    parent::setUp();

    if (! Shop::find(1)) {
      $shop = new Shop(['code' => 'default', 'title' => 'Default Shop']);
      $shop->id = 1;
      $shop->save();
    }

    AdminSetting::query()->updateOrCreate(
      ['key' => 'project_modules'],
      ['value' => json_encode(['network_catalog' => ['enabled' => true]])]
    );
    Cache::flush();
  }

  private function makeProduct(): Product
  {
    return Product::withoutEvents(fn () => Product::query()->create([
      'title' => 'Товар с возвращённым оффером ' . uniqid(),
      'articul' => 'RESTORE-' . uniqid(),
      'shop_id' => 1,
      'status_id' => 1,
      'unit_type_id' => 1,
    ]));
  }

  private function makeShop(): Shop
  {
    return Shop::query()->create([
      'code' => 'restore-shop-' . uniqid(),
      'title' => 'Магазин для оффера',
    ]);
  }

  private function priceRows(Product $product, Shop $shop): int
  {
    return Price::withTrashed()
      ->where('product_id', $product->id)
      ->where('shop_id', $shop->id)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->count();
  }

  /**
   * @test
   * Сервис: завели оффер, убрали товар из магазина, завели снова.
   */
  public function save_restores_soft_deleted_price_and_remain(): void
  {
    $product = $this->makeProduct();
    $shop = $this->makeShop();
    $service = app(ProductShopPriceService::class);

    $service->save($product, $shop, 100.0, 5);
    $service->delete($product, $shop);

    $service->save($product, $shop, 150.0, 8);

    $price = Price::query()
      ->where('product_id', $product->id)
      ->where('shop_id', $shop->id)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->first();

    $this->assertNotNull($price, 'Цена не восстановлена после повторного заведения оффера');
    $this->assertSame(150.0, (float) $price->value);
    $this->assertNull($price->deleted_at);
    $this->assertSame(1, $this->priceRows($product, $shop), 'Появился дубль строки цены');

    $remain = Remain::query()
      ->where('product_id', $product->id)
      ->where('shop_id', $shop->id)
      ->first();

    $this->assertNotNull($remain, 'Остаток не восстановлен');
    $this->assertSame(8, (int) $remain->quantity);
  }

  /**
   * @test
   * Мутация карточки: тот же сценарий через ProductUpdate — именно он падал
   * на dev с «Duplicate entry '56019-1-1'».
   */
  public function product_update_mutation_restores_soft_deleted_price(): void
  {
    $product = $this->makeProduct();
    $shop = $this->makeShop();
    $service = app(ProductShopPriceService::class);

    $service->save($product, $shop, 100.0, 5);
    $service->delete($product, $shop);

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));
    $this->actingAs($user);

    $result = (new ProductUpdate())(null, [
      'id' => $product->id,
      'shop_id' => $shop->id,
      'title' => $product->title,
      'price' => 199.0,
      'quantity' => 3,
    ]);

    $this->assertSame(200, $result['code']);

    $price = Price::query()
      ->where('product_id', $product->id)
      ->where('shop_id', $shop->id)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->first();

    $this->assertNotNull($price, 'Цена не восстановлена мутацией');
    $this->assertSame(199.0, (float) $price->value);
    $this->assertSame(1, $this->priceRows($product, $shop), 'Появился дубль строки цены');
  }
}
