<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkCatalog;

use App\Models\AdminSetting;
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
 * Создание вариативного товара при сетевом каталоге.
 *
 * ConfigurableProductCreate объявлял shop_id: Int! — в сетевом режиме магазин
 * в админке не выбирается, и мутация падала на обязательной переменной ещё до
 * резолвера. Схема ослаблена до Int, магазин подставляется якорем (тот же
 * паттерн, что в ProductCreate/ProductUpdate); вне сетевого режима отсутствие
 * магазина по-прежнему отвергается.
 */
class NetworkConfigurableProductCreateTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private Shop $anchor;

  protected function setUp(): void
  {
    parent::setUp();

    app(NetworkCatalogMode::class)->flushCache();
    $this->anchor = $this->resolveAnchorShop();
    $this->enableConfigurableProducts();
    $this->actingAsGlobalAdmin();
  }

  protected function tearDown(): void
  {
    app(NetworkCatalogMode::class)->flushCache();
    parent::tearDown();
  }

  /** @test */
  public function test_configurable_product_created_in_anchor_without_shop_id(): void
  {
    $this->setNetworkCatalog(true);

    $children = [$this->makeChild(), $this->makeChild()];
    $title = 'Сетевой вариативный ' . uniqid();

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($title: String!, $children_ids: [Int!]!) {
        ConfigurableProductCreate(title: $title, children_ids: $children_ids) {
          code
          message
        }
      }
    ', [
      'title' => $title,
      'children_ids' => array_map(fn (Product $p) => $p->id, $children),
    ]);

    $response->assertJsonPath('data.ConfigurableProductCreate.code', 200);

    $parent = Product::where('title', $title)->firstOrFail();
    $this->assertTrue((bool) $parent->is_configurable);
    $this->assertSame(
      $this->anchor->id,
      (int) $parent->shop_id,
      'Вариативный сетевого каталога создаётся в якорном магазине сети.'
    );

    foreach ($children as $child) {
      $this->assertSame($parent->id, (int) $child->fresh()->parent_id);
    }
  }

  /** @test */
  public function test_shop_id_stays_required_when_network_catalog_disabled(): void
  {
    $this->setNetworkCatalog(false);

    $children = [$this->makeChild(), $this->makeChild()];
    $title = 'Легаси вариативный ' . uniqid();

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($title: String!, $children_ids: [Int!]!) {
        ConfigurableProductCreate(title: $title, children_ids: $children_ids) {
          code
        }
      }
    ', [
      'title' => $title,
      'children_ids' => array_map(fn (Product $p) => $p->id, $children),
    ]);

    $this->assertNotEmpty(
      $response->json('errors'),
      'При выключенном флаге создание вариативного без магазина должно отвергаться.'
    );
    $this->assertNull(Product::where('title', $title)->first());
  }

  private function makeChild(): Product
  {
    return Product::withoutEvents(fn () => Product::create([
      'title' => 'Вариант ' . uniqid(),
      'articul' => 'VAR-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->anchor->id,
      'status_id' => Status::CODE_ACTIVE,
      'unit_type_id' => Type::UNIT_PIECE,
    ]));
  }

  private function actingAsGlobalAdmin(): User
  {
    AdminSetting::setValue('role_access_matrix_enabled', '0', 'boolean');

    $admin = User::create([
      'name' => 'Admin',
      'phone' => '+7903' . mt_rand(1000000, 9999999),
      'email' => uniqid('admin_') . '@test.local',
      'password' => bcrypt('secret'),
    ]);
    $admin->assignRole(Role::findOrCreate(Role::ADMIN));
    $this->actingAs($admin);

    return $admin;
  }

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

  private function enableConfigurableProducts(): void
  {
    $this->writeModule('configurable_products', true);
  }

  private function setNetworkCatalog(bool $enabled): void
  {
    $this->writeModule('network_catalog', $enabled);
    app(NetworkCatalogMode::class)->flushCache();
  }

  private function writeModule(string $key, bool $enabled): void
  {
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = $raw ? (json_decode((string) $raw, true) ?: []) : [];
    $modules[$key] = ['label' => $key, 'enabled' => $enabled];

    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
  }
}
