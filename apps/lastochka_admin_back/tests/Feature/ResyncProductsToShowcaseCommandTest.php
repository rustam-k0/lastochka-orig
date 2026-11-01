<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionProperty;
use Tests\TestCase;

/**
 * products:resync-showcase — досылка карточек товаров на витрину.
 *
 * Главное, что проверяем — ПОРЯДОК: карточка обязана уйти в outbox раньше
 * офферов. Consumer витрины отбрасывает price_updated/stock_updated, если
 * товара у него ещё нет (ack без ретрая), поэтому обратный порядок означает
 * потерянные цены — ровно так на mendev dev офферы не доезжали.
 */
class ResyncProductsToShowcaseCommandTest extends TestCase
{
  use DatabaseTransactions;

  protected function setUp(): void
  {
    // Observers пакета регистрируются один раз на процесс, а PHPUnit
    // пересоздаёт app на каждый тест — без сброса события в outbox пишутся
    // только для первого теста процесса.
    (new ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered'))
      ->setValue(null, false);

    parent::setUp();

    // products.shop_id — NOT NULL с FK, а база под тесты может быть пустой.
    if (! Shop::find(1)) {
      $shop = new Shop(['code' => 'default', 'title' => 'Default Shop']);
      $shop->id = 1;
      $shop->save();
    }
  }

  private function makeShop(string $code): Shop
  {
    return Shop::query()->create([
      'code' => $code,
      'title' => 'Магазин ' . $code,
    ]);
  }

  private function makeProduct(): Product
  {
    // Без событий: товар из 1С создаёт консьюмер, а не локальный код. Иначе
    // Observer пакета допишет вторую (локальную) метадату, origin станет
    // неоднозначным и в событие уедет локальный id вместо GUID.
    $product = Product::withoutEvents(fn () => Product::query()->create([
      'title' => 'Товар для досылки ' . uniqid(),
      'articul' => 'RESYNC-' . uniqid(),
      'shop_id' => 1,
      'status_id' => 1,
      'unit_type_id' => 1,
    ]));

    // Товар «из 1С»: витрина знает его по GUID, а не по локальному id.
    EventSyncMetadata::create([
      'entity_type' => 'products',
      'entity_id' => $product->id,
      'source' => '1c',
      'external_id' => 'GUID-' . uniqid(),
      'shop_code' => 'some-other-shop',
    ]);

    return $product;
  }

  private function outboxFor(Product $product)
  {
    return EventOutbox::query()
      ->where('entity_type', 'products')
      ->where('entity_id', $product->id)
      ->orderBy('id')
      ->get();
  }

  /** @test */
  public function publishes_card_event_for_given_ids(): void
  {
    $product = $this->makeProduct();
    EventOutbox::query()->where('entity_id', $product->id)->delete();

    $this->artisan('products:resync-showcase', ['--ids' => (string) $product->id, '--sleep' => 0])
      ->assertSuccessful();

    $events = $this->outboxFor($product);

    $this->assertCount(1, $events);
    $this->assertSame('product.updated', $events[0]->routing_key);
  }

  /**
   * @test
   * Карточка первой, офферы следом — иначе витрина потеряет цены.
   */
  public function publishes_card_before_offers(): void
  {
    $product = $this->makeProduct();
    $shop = $this->makeShop('resync-shop-' . uniqid());

    Price::query()->create([
      'product_id' => $product->id,
      'shop_id' => $shop->id,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 42.5,
      'step' => 1,
    ]);
    Remain::query()->create([
      'product_id' => $product->id,
      'shop_id' => $shop->id,
      'quantity' => 7,
    ]);

    EventOutbox::query()->where('entity_id', $product->id)->delete();

    $this->artisan('products:resync-showcase', [
      '--ids' => (string) $product->id,
      '--with-offers' => true,
      '--sleep' => 0,
    ])->assertSuccessful();

    $keys = $this->outboxFor($product)->pluck('routing_key')->all();

    $this->assertSame([
      'product.updated',
      'product.price_updated',
      'product.stock_updated',
    ], $keys, 'Офферы обогнали карточку — витрина их отбросит');
  }

  /**
   * @test
   * Оффер несёт остаток из remains и GUID товара, а не локальный id.
   */
  public function offer_events_carry_quantity_and_external_id(): void
  {
    $product = $this->makeProduct();
    $shop = $this->makeShop('resync-shop-' . uniqid());

    Price::query()->create([
      'product_id' => $product->id,
      'shop_id' => $shop->id,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 99.9,
      'step' => 1,
    ]);
    Remain::query()->create([
      'product_id' => $product->id,
      'shop_id' => $shop->id,
      'quantity' => 13,
    ]);

    EventOutbox::query()->where('entity_id', $product->id)->delete();

    $this->artisan('products:resync-showcase', [
      '--ids' => (string) $product->id,
      '--with-offers' => true,
      '--sleep' => 0,
    ])->assertSuccessful();

    $guid = EventSyncMetadata::query()
      ->where('entity_type', 'products')
      ->where('entity_id', $product->id)
      ->value('external_id');

    $stock = $this->outboxFor($product)->firstWhere('routing_key', 'product.stock_updated');
    $payload = is_array($stock->payload) ? $stock->payload : json_decode($stock->payload, true);

    $this->assertSame(13, $payload['quantity']);
    $this->assertSame($guid, $payload['id']);
    $this->assertSame($shop->code, $payload['shop_code']);
  }

  /** @test */
  public function dry_run_publishes_nothing(): void
  {
    $product = $this->makeProduct();
    EventOutbox::query()->where('entity_id', $product->id)->delete();

    $this->artisan('products:resync-showcase', [
      '--ids' => (string) $product->id,
      '--with-offers' => true,
      '--dry-run' => true,
      '--sleep' => 0,
    ])->assertSuccessful();

    $this->assertCount(0, $this->outboxFor($product));
  }
}
