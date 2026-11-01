<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkCatalog;

use App\Models\AdminSetting;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Создание товара из админки при сетевом каталоге.
 *
 * Карточка одна на всю сеть и живёт в якорном магазине, поэтому в админке
 * магазин не выбирается: ProductCreate/ProductUpdate приходят без shop_id.
 * До фикса валидатор требовал shop_id у глобального админа (adminRequiredRule)
 * и создать товар в сетевом режиме было невозможно.
 *
 * Цена в сетевом режиме тоже необязательна: её ведёт 1С пер-магазин.
 */
class NetworkProductCreateTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private Shop $anchor;

  protected function setUp(): void
  {
    parent::setUp();

    app(NetworkCatalogMode::class)->flushCache();
    $this->anchor = $this->resolveAnchorShop();
  }

  protected function tearDown(): void
  {
    app(NetworkCatalogMode::class)->flushCache();
    parent::tearDown();
  }

  /** @test */
  public function test_global_admin_creates_product_without_shop_id_into_anchor_shop(): void
  {
    $this->actingAsGlobalAdmin();
    $this->setNetworkCatalog(true);

    $articul = 'NET-CREATE-' . mb_strtoupper(uniqid());

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($articul: String, $price: Float, $info_weight_type_id: Int) {
        ProductCreate(
          articul: $articul
          price: $price
          info_weight_type_id: $info_weight_type_id
          title: "Сетевая карточка"
        ) {
          code
        }
      }
    ', [
      'articul' => $articul,
      'price' => 149.5,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
    ]);

    $response->assertJsonPath('data.ProductCreate.code', 200);

    $product = Product::where('articul', $articul)->firstOrFail();
    $this->assertSame(
      $this->anchor->id,
      (int) $product->shop_id,
      'Карточка сетевого каталога должна создаваться в якорном магазине сети.'
    );

    // Цена, если её всё же прислали (создание товара вручную), уходит в якорь.
    $this->assertDatabaseHas('prices', [
      'product_id' => $product->id,
      'shop_id' => $this->anchor->id,
      'type_id' => Type::PRICE_DEFAULT,
    ]);
  }

  /** @test */
  public function test_global_admin_creates_product_without_price_in_network_mode(): void
  {
    $this->actingAsGlobalAdmin();
    $this->setNetworkCatalog(true);

    $articul = 'NET-NOPRICE-' . mb_strtoupper(uniqid());

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($articul: String, $info_weight_type_id: Int) {
        ProductCreate(
          articul: $articul
          info_weight_type_id: $info_weight_type_id
          title: "Сетевая карточка без цены"
        ) {
          code
        }
      }
    ', [
      'articul' => $articul,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
    ]);

    $response->assertJsonPath('data.ProductCreate.code', 200);

    $product = Product::where('articul', $articul)->firstOrFail();
    $this->assertSame($this->anchor->id, (int) $product->shop_id);
    $this->assertSame(
      0,
      Price::where('product_id', $product->id)->count(),
      'У сетевой карточки нет «своей» цены — строки prices создаёт только 1С.'
    );
  }

  /** @test */
  public function test_shop_id_stays_required_when_network_catalog_disabled(): void
  {
    $this->actingAsGlobalAdmin();
    $this->setNetworkCatalog(false);

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($articul: String, $price: Float, $info_weight_type_id: Int) {
        ProductCreate(
          articul: $articul
          price: $price
          info_weight_type_id: $info_weight_type_id
          title: "Легаси карточка"
        ) {
          code
        }
      }
    ', [
      'articul' => 'LEGACY-' . mb_strtoupper(uniqid()),
      'price' => 149.5,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
    ]);

    $this->assertNotEmpty(
      $response->json('errors.0.extensions.validation.shop_id'),
      'При выключенном флаге shop_id остаётся обязательным для глобального админа.'
    );
  }

  /** @test */
  public function test_saving_content_field_does_not_touch_per_shop_prices(): void
  {
    $this->actingAsGlobalAdmin();
    $this->setNetworkCatalog(true);

    $shopA = Shop::create(['title' => 'A ' . uniqid(), 'code' => 'net-a-' . uniqid()]);
    $shopB = Shop::create(['title' => 'B ' . uniqid(), 'code' => 'net-b-' . uniqid()]);

    $product = Product::withoutEvents(fn () => Product::create([
      'title' => 'Сетевая карточка',
      'articul' => 'NET-EDIT-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->anchor->id,
      'status_id' => Status::CODE_ACTIVE,
      'unit_type_id' => Type::UNIT_PIECE,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
    ]));

    foreach ([[$shopA, 100.0], [$shopB, 130.0]] as [$shop, $value]) {
      Price::withoutEvents(fn () => (new Price())->forceFill([
        'product_id' => $product->id,
        'shop_id' => $shop->id,
        'type_id' => Type::PRICE_DEFAULT,
        'value' => $value,
        'step' => 1,
      ])->save());
    }

    $before = Price::where('product_id', $product->id)
      ->orderBy('shop_id')
      ->get(['shop_id', 'value', 'step'])
      ->toArray();

    // Карточка сохраняется из админки без price/quantity (их ведёт 1С).
    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($id: Int, $title: String) {
        ProductUpdate(id: $id, title: $title) { code }
      }
    ', [
      'id' => $product->id,
      'title' => 'Сетевая карточка (правка контента)',
    ]);

    $response->assertJsonPath('data.ProductUpdate.code', 200);
    $this->assertSame('Сетевая карточка (правка контента)', $product->fresh()->title);

    $after = Price::where('product_id', $product->id)
      ->orderBy('shop_id')
      ->get(['shop_id', 'value', 'step'])
      ->toArray();

    $this->assertSame($before, $after, 'Правка контент-полей не должна трогать пер-магазинные цены.');
    $this->assertSame(
      2,
      Price::where('product_id', $product->id)->count(),
      'Лишних строк цены (в том числе в якоре) появиться не должно.'
    );
  }

  /** @test */
  public function test_shop_scoped_user_gets_own_shop_not_anchor(): void
  {
    AdminSetting::setValue('role_access_matrix_enabled', '0', 'boolean');
    $shop = Shop::create(['title' => 'Магазин продавца ' . uniqid(), 'code' => 'seller-' . uniqid()]);

    $user = User::create([
      'name' => 'Shop User ' . uniqid(),
      'phone' => '+7902' . mt_rand(1000000, 9999999),
      'email' => uniqid('shop_') . '@test.local',
      'password' => bcrypt('secret'),
    ]);
    $user->assignRole(Role::findOrCreate(Role::SHOP));
    $user->shops()->create(['shop_id' => $shop->id]);
    $this->assertFalse($user->isGlobalScope());
    $this->actingAs($user);

    $this->setNetworkCatalog(true);

    $articul = 'SELLER-' . mb_strtoupper(uniqid());

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($articul: String, $price: Float, $info_weight_type_id: Int) {
        ProductCreate(
          articul: $articul
          price: $price
          info_weight_type_id: $info_weight_type_id
          title: "Товар продавца"
        ) {
          code
        }
      }
    ', [
      'articul' => $articul,
      'price' => 99.0,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
    ]);

    $response->assertJsonPath('data.ProductCreate.code', 200);

    $product = Product::where('articul', $articul)->firstOrFail();
    $this->assertSame(
      $shop->id,
      (int) $product->shop_id,
      'Не-глобальному пользователю якорь не подставляется — товар остаётся в его магазине.'
    );
    $this->assertNotSame($this->anchor->id, (int) $product->shop_id);
  }

  /**
   * Глобальный админ с выключенной матрицей доступов (паттерн ProductArticulTest):
   * тестовая БД может быть пустой, поэтому создаём пользователя явно.
   */
  private function actingAsGlobalAdmin(): User
  {
    AdminSetting::setValue('role_access_matrix_enabled', '0', 'boolean');

    $admin = User::create([
      'name' => 'Admin',
      'phone' => '+7901' . mt_rand(1000000, 9999999),
      'email' => uniqid('admin_') . '@test.local',
      'password' => bcrypt('secret'),
    ]);
    $admin->assignRole(Role::findOrCreate(Role::ADMIN));
    $this->assertTrue($admin->isGlobalScope());
    $this->actingAs($admin);

    return $admin;
  }

  /**
   * Якорь сети — магазин с default=1 (конвенция NetworkCatalogMode::anchorShopId).
   * Поле не в $fillable у Shop, поэтому проставляем отдельным save().
   */
  private function resolveAnchorShop(): Shop
  {
    $existing = Shop::query()->where('default', 1)->first();

    if ($existing !== null) {
      return $existing;
    }

    $anchor = Shop::create([
      'title' => 'Anchor ' . uniqid(),
      'code' => 'anchor-' . uniqid(),
    ]);
    $anchor->default = 1;
    $anchor->save();

    return $anchor;
  }

  private function setNetworkCatalog(bool $enabled): void
  {
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = $raw ? (json_decode((string) $raw, true) ?: []) : [];
    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => $enabled];

    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );

    app(NetworkCatalogMode::class)->flushCache();
  }
}
