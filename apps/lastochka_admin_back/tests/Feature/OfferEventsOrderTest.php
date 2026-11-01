<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Product\ProductUpdate;
use App\Models\Product;
use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Порядок публикации: карточка товара обязана уходить в outbox РАНЬШЕ
 * пер-магазинных price_updated / stock_updated.
 *
 * Витрина строит оффер (store_products) только для уже существующего у неё
 * товара. Если офферное событие приходит первым, consumer выбрасывает его с
 * ack и без ретрая («Custom event received for non-existent entity»), и цена
 * теряется навсегда — товар остаётся невидимым в каталоге, хотя в админке у
 * него всё заполнено. На mendev dev так потерялись офферы у товаров, которых
 * на витрине ещё не было: события ушли в 14:00:22, карточка создалась в
 * 14:00:25.
 *
 * Outbox публикуется по возрастанию id, поэтому достаточно проверить порядок
 * записей в нём.
 */
class OfferEventsOrderTest extends TestCase
{
  use DatabaseTransactions;

  protected function setUp(): void
  {
    // Observers пакета регистрируются один раз на процесс — без сброса
    // события пишутся только для первого теста процесса.
    (new ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered'))
      ->setValue(null, false);

    parent::setUp();

    if (! Shop::find(1)) {
      $shop = new Shop(['code' => 'default', 'title' => 'Default Shop']);
      $shop->id = 1;
      $shop->save();
    }

    // Офферы живут только в сетевом каталоге — без флага мутация их не шлёт.
    AdminSetting::query()->updateOrCreate(
      ['key' => 'project_modules'],
      ['value' => json_encode(['network_catalog' => ['enabled' => true]])]
    );
    Cache::forget('network_catalog_enabled');
    Cache::flush();
  }

  private function makeProduct(): Product
  {
    // Товар «из 1С»: витрина знает его по GUID. Создаём без событий, иначе
    // Observer допишет вторую метадату и origin станет неоднозначным.
    $product = Product::withoutEvents(fn () => Product::query()->create([
      'title' => 'Товар для проверки порядка ' . uniqid(),
      'articul' => 'ORDER-' . uniqid(),
      'shop_id' => 1,
      'status_id' => 1,
      'unit_type_id' => 1,
    ]));

    EventSyncMetadata::create([
      'entity_type' => 'products',
      'entity_id' => $product->id,
      'source' => '1c',
      'external_id' => 'GUID-' . uniqid(),
      'shop_code' => 'shop-of-first-import',
    ]);

    return $product;
  }

  private function makeShop(): Shop
  {
    return Shop::query()->create([
      'code' => 'order-shop-' . uniqid(),
      'title' => 'Магазин для оффера',
    ]);
  }

  private function makeAdmin(): User
  {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

    return $user;
  }

  /**
   * @return list<string>
   */
  private function outboxKeys(Product $product): array
  {
    return EventOutbox::query()
      ->where('entity_type', 'products')
      ->where('entity_id', $product->id)
      ->orderBy('id')
      ->pluck('routing_key')
      ->all();
  }

  private function clearOutbox(Product $product): void
  {
    EventOutbox::query()->where('entity_id', $product->id)->delete();
  }

  /**
   * @test
   * Прямое сохранение оффера (мутация ProductShopPriceSave).
   */
  public function save_publishes_card_before_offer_events(): void
  {
    $product = $this->makeProduct();
    $shop = $this->makeShop();
    $this->clearOutbox($product);

    app(ProductShopPriceService::class)->save($product, $shop, 109.99, 15);

    $this->assertSame([
      'product.updated',
      'product.price_updated',
      'product.stock_updated',
    ], $this->outboxKeys($product), 'Оффер обогнал карточку — витрина его отбросит');
  }

  /**
   * @test
   * Массовое проставление цены по всей сети: карточка публикуется один раз,
   * до офферов, а не на каждый магазин.
   */
  public function apply_to_all_publishes_card_once_before_offers(): void
  {
    $product = $this->makeProduct();
    $this->makeShop();
    $this->makeShop();
    $this->clearOutbox($product);

    app(ProductShopPriceService::class)->applyToAll($product, 55.5, 3);

    $keys = $this->outboxKeys($product);

    $this->assertSame('product.updated', $keys[0] ?? null, 'Первой должна уходить карточка');
    $this->assertSame(
      1,
      count(array_filter($keys, fn ($key) => $key === 'product.updated')),
      'Карточка должна публиковаться один раз на всю операцию, а не на каждый магазин'
    );
    $this->assertContains('product.price_updated', $keys);
  }

  /**
   * @test
   * Сохранение карточки товара с ценой (мутация ProductUpdate) — тот самый
   * путь, на котором офферы уходили раньше карточки.
   */
  public function product_update_mutation_publishes_card_before_offer_events(): void
  {
    $product = $this->makeProduct();
    $shop = $this->makeShop();
    $this->clearOutbox($product);

    // Мутация ходит в Auth::user()->isGlobalScope() при выборке товара.
    $this->actingAs($this->makeAdmin());

    (new ProductUpdate())(null, [
      'id' => $product->id,
      'shop_id' => $shop->id,
      'title' => $product->title,
      'price' => 77.7,
      'quantity' => 4,
    ]);

    $keys = $this->outboxKeys($product);
    $card = array_search('product.updated', $keys, true);
    $offer = array_search('product.price_updated', $keys, true);

    $this->assertNotFalse($card, 'Карточка не опубликована');
    $this->assertNotFalse($offer, 'Оффер не опубликован');
    $this->assertLessThan($offer, $card, 'Оффер обогнал карточку — витрина его отбросит');
  }
}
