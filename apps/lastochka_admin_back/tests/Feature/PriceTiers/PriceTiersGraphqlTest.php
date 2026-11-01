<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Rules\PriceTiersRule;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Support\InteractsWithPriceTiers;
use Tests\TestCase;

/**
 * GraphQL-контракт «цены от количества» (MID-308): чтение Product.price_tiers,
 * аргумент price_tiers в ProductShopPriceSave / ProductShopPricesApplyToAll /
 * ProductCreate / ProductUpdate, валидация ручного ввода, отказ при
 * выключенном флаге, правило «лесенка требует цены».
 */
class PriceTiersGraphqlTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;
    use InteractsWithPriceTiers;

    private const SAVE = /** @lang GraphQL */ '
        mutation ($product_id: Int!, $shop_id: Int!, $price: Float!, $quantity: Int!, $price_tiers: [PriceTierInput!]) {
            ProductShopPriceSave(product_id: $product_id, shop_id: $shop_id, price: $price, quantity: $quantity, price_tiers: $price_tiers) {
                value
                shop_id
            }
        }
    ';

    private const APPLY_TO_ALL = /** @lang GraphQL */ '
        mutation ($product_id: Int!, $price: Float!, $quantity: Int!, $price_tiers: [PriceTierInput!]) {
            ProductShopPricesApplyToAll(product_id: $product_id, price: $price, quantity: $quantity, price_tiers: $price_tiers)
        }
    ';

    private const UPDATE = /** @lang GraphQL */ '
        mutation ($id: Int, $shop_id: Int, $price: Float, $price_tiers: [PriceTierInput!]) {
            ProductUpdate(id: $id, shop_id: $shop_id, price: $price, price_tiers: $price_tiers) { code message }
        }
    ';

    private const CREATE = /** @lang GraphQL */ '
        mutation ($title: String, $shop_id: Int, $price: Float, $info_weight_type_id: Int, $price_tiers: [PriceTierInput!]) {
            ProductCreate(title: $title, shop_id: $shop_id, price: $price, info_weight_type_id: $info_weight_type_id, price_tiers: $price_tiers) { code message }
        }
    ';

    private const UPDATE_TIERS_ONLY = /** @lang GraphQL */ '
        mutation ($id: Int, $shop_id: Int, $price_tiers: [PriceTierInput!]) {
            ProductUpdate(id: $id, shop_id: $shop_id, price_tiers: $price_tiers) { code message }
        }
    ';

    private const CREATE_WITHOUT_PRICE = /** @lang GraphQL */ '
        mutation ($title: String, $info_weight_type_id: Int, $price_tiers: [PriceTierInput!]) {
            ProductCreate(title: $title, info_weight_type_id: $info_weight_type_id, price_tiers: $price_tiers) { code message }
        }
    ';

    private const READ = /** @lang GraphQL */ '
        query ($id: Int) {
            admin_products_item(id: $id) { id price_tiers { shop_id min_quantity value } }
        }
    ';

    private User $user;
    private Shop $shop;
    private Product $product;
    private ProductShopPriceService $tiers;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forever(NetworkCatalogMode::CACHE_KEY, true);

        $this->user = $this->adminUser();
        $this->shop = $this->makeShop();
        $this->product = Product::create([
            'title' => 'Товар с лесенкой ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
        $this->tiers = app(ProductShopPriceService::class);

        $this->setPriceTiersModule(true);
    }

    protected function tearDown(): void
    {
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
        Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'Tier Shop ' . uniqid(),
            'code' => 'tier-gql-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function saveVars(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 279.0,
            'quantity' => 7,
        ], $overrides);
    }

    private function seedLadder(): void
    {
        $this->tiers->syncPriceTiers($this->product->id, $this->shop->id, PriceTierLadder::normalize([
            ['min_quantity' => 3, 'price' => 100],
        ]));
    }

    public function test_save_writes_price_and_ladder(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars([
            'price_tiers' => [
                ['min_quantity' => 5, 'value' => 199],
                ['min_quantity' => 2, 'value' => 249],
            ],
        ]));

        $response->assertJson(['data' => ['ProductShopPriceSave' => ['value' => 279.0, 'shop_id' => $this->shop->id]]]);
        $this->assertSame([
            ['min_quantity' => 2.0, 'price' => 249.0],
            ['min_quantity' => 5.0, 'price' => 199.0],
        ], $this->tiers->ladderFor($this->product->id, $this->shop->id));
    }

    public function test_save_without_argument_or_with_null_keeps_ladder_and_empty_list_clears_it(): void
    {
        $this->seedLadder();

        $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars())->assertJsonMissing(['errors']);
        $this->assertCount(1, $this->tiers->ladderFor($this->product->id, $this->shop->id));

        $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars(['price_tiers' => null]))->assertJsonMissing(['errors']);
        $this->assertCount(1, $this->tiers->ladderFor($this->product->id, $this->shop->id));

        $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars(['price_tiers' => []]))->assertJsonMissing(['errors']);
        $this->assertSame([], $this->tiers->ladderFor($this->product->id, $this->shop->id));
    }

    public function test_ladder_must_be_strictly_decreasing(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars([
            'price_tiers' => [
                ['min_quantity' => 2, 'value' => 249],
                ['min_quantity' => 5, 'value' => 249],
            ],
        ]));

        $response->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_NOT_DECREASING);
        $this->assertDatabaseMissing('price_tiers', ['product_id' => $this->product->id]);
        $this->assertDatabaseMissing('prices', ['product_id' => $this->product->id, 'shop_id' => $this->shop->id]);
    }

    public function test_thresholds_must_be_unique(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars([
            'price_tiers' => [
                ['min_quantity' => 2, 'value' => 249],
                ['min_quantity' => 2.0, 'value' => 199],
            ],
        ]));

        $response->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_DUPLICATE);
    }

    public function test_threshold_and_value_must_be_positive_with_indexed_keys(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars([
            'price_tiers' => [
                ['min_quantity' => 2, 'value' => 249],
                ['min_quantity' => 0, 'value' => -5],
            ],
        ]));

        $response->assertGraphQLValidationKeys(['price_tiers.1.min_quantity', 'price_tiers.1.value']);
    }

    /**
     * F4 финального ревью: лесенка длиннее PriceTierLadder::MAX_TIERS не должна
     * молча проходить строгую валидацию ручного ввода. normalize() возвращает
     * null не только на дублях порогов (единственный случай, который правило
     * проверяло раньше), но и на превышении лимита — без отдельной проверки
     * PriceTiersRule просто ничего не сообщал бы об этом ($fail не вызывался).
     */
    public function test_ladder_longer_than_limit_is_rejected(): void
    {
        $tiers = [];
        for ($i = 1; $i <= PriceTierLadder::MAX_TIERS + 1; $i++) {
            $tiers[] = ['min_quantity' => $i, 'value' => 1000 - $i];
        }

        $response = $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars(['price_tiers' => $tiers]));

        $response->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_TOO_MANY_TIERS);
        $this->assertDatabaseMissing('price_tiers', ['product_id' => $this->product->id]);
    }

    public function test_flag_disabled_rejects_argument_even_empty(): void
    {
        $this->setPriceTiersModule(false);

        $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars([
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ]))->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_DISABLED);

        $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars(['price_tiers' => []]))
            ->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_DISABLED);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $this->product->id]);
        $this->assertDatabaseMissing('prices', ['product_id' => $this->product->id, 'shop_id' => $this->shop->id]);
    }

    public function test_flag_disabled_mutation_without_argument_works_as_before(): void
    {
        $this->setPriceTiersModule(false);

        $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars())
            ->assertJson(['data' => ['ProductShopPriceSave' => ['value' => 279.0]]]);

        $this->actingAs($this->user)->graphQL(self::SAVE, $this->saveVars(['price_tiers' => null]))
            ->assertJson(['data' => ['ProductShopPriceSave' => ['value' => 279.0]]]);
    }

    public function test_apply_to_all_writes_one_ladder_to_every_shop(): void
    {
        $second = $this->makeShop();
        $this->tiers->syncPriceTiers($this->product->id, $second->id, PriceTierLadder::normalize([
            ['min_quantity' => 9, 'price' => 1],
        ]));

        $this->actingAs($this->user)->graphQL(self::APPLY_TO_ALL, [
            'product_id' => $this->product->id,
            'price' => 279.0,
            'quantity' => 5,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertJsonMissing(['errors']);

        $expected = [['min_quantity' => 2.0, 'price' => 249.0]];
        $this->assertSame($expected, $this->tiers->ladderFor($this->product->id, $this->shop->id));
        $this->assertSame($expected, $this->tiers->ladderFor($this->product->id, $second->id));
    }

    public function test_apply_to_all_without_argument_keeps_ladders(): void
    {
        $this->seedLadder();

        $this->actingAs($this->user)->graphQL(self::APPLY_TO_ALL, [
            'product_id' => $this->product->id,
            'price' => 279.0,
            'quantity' => 5,
        ])->assertJsonMissing(['errors']);

        $this->assertCount(1, $this->tiers->ladderFor($this->product->id, $this->shop->id));
    }

    public function test_product_field_returns_tiers_of_all_shops(): void
    {
        $second = $this->makeShop();
        $this->seedLadder();
        $this->tiers->syncPriceTiers($this->product->id, $second->id, PriceTierLadder::normalize([
            ['min_quantity' => 0.5, 'price' => 520.5],
        ]));

        $tiers = $this->actingAs($this->user)->graphQL(self::READ, ['id' => $this->product->id])
            ->json('data.admin_products_item.price_tiers');

        $this->assertEqualsCanonicalizing([
            ['shop_id' => $this->shop->id, 'min_quantity' => 3, 'value' => 100],
            ['shop_id' => $second->id, 'min_quantity' => 0.5, 'value' => 520.5],
        ], $tiers);
    }

    public function test_product_field_is_empty_when_flag_disabled(): void
    {
        $this->seedLadder();
        $this->setPriceTiersModule(false);

        $this->actingAs($this->user)->graphQL(self::READ, ['id' => $this->product->id])
            ->assertJson(['data' => ['admin_products_item' => ['price_tiers' => []]]]);
    }

    public function test_product_update_writes_ladder_of_product_shop_in_regular_mode(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);

        $this->actingAs($this->user)->graphQL(self::UPDATE, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 279.0,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertJsonMissing(['errors']);

        $this->assertSame(
            [['min_quantity' => 2.0, 'price' => 249.0]],
            $this->tiers->ladderFor($this->product->id, $this->shop->id)
        );

        // Аргумент не передан: лесенка остаётся.
        $this->actingAs($this->user)->graphQL(self::UPDATE, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 289.0,
        ])->assertJsonMissing(['errors']);

        $this->assertCount(1, $this->tiers->ladderFor($this->product->id, $this->shop->id));
    }

    public function test_product_create_writes_ladder(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);
        $title = 'Новый товар с лесенкой ' . uniqid();

        $this->actingAs($this->user)->graphQL(self::CREATE, [
            'title' => $title,
            'shop_id' => $this->shop->id,
            'price' => 279.0,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertJsonMissing(['errors']);

        $created = Product::where('title', $title)->firstOrFail();

        $this->assertSame(
            [['min_quantity' => 2.0, 'price' => 249.0]],
            $this->tiers->ladderFor($created->id, $this->shop->id)
        );
    }

    public function test_product_update_rejects_argument_when_flag_disabled(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);
        $this->setPriceTiersModule(false);

        $this->actingAs($this->user)->graphQL(self::UPDATE, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 279.0,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_DISABLED);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $this->product->id]);
    }

    private function makePrice(float $value = 279.0): Price
    {
        return Price::create([
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => $value,
            'step' => 1,
        ]);
    }

    private function priceUpdatedEvents(int $productId): int
    {
        return EventOutbox::where('entity_type', 'products')
            ->where('entity_id', $productId)
            ->where('event_type', 'price_updated')
            ->count();
    }

    public function test_ladder_requires_price_of_the_pair(): void
    {
        // Сетевой каталог: у пары (товар, магазин) нет строки цены, цена в
        // мутации не передана — иначе ушло бы офферное событие с price = 0.
        $this->actingAs($this->user)->graphQL(self::UPDATE_TIERS_ONLY, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_PRICE_REQUIRED);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $this->product->id]);
        $this->assertSame(0, $this->priceUpdatedEvents($this->product->id));
    }

    public function test_ladder_is_accepted_when_pair_has_price_or_price_comes_in_same_mutation(): void
    {
        // Цена передана в этой же мутации.
        $this->actingAs($this->user)->graphQL(self::UPDATE, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 279.0,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertJsonMissing(['errors']);

        // Строка цены уже есть, цена не передана.
        $this->actingAs($this->user)->graphQL(self::UPDATE_TIERS_ONLY, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price_tiers' => [['min_quantity' => 3, 'value' => 239]],
        ])->assertJsonMissing(['errors']);

        $this->assertSame(
            [['min_quantity' => 3.0, 'price' => 239.0]],
            $this->tiers->ladderFor($this->product->id, $this->shop->id)
        );
    }

    public function test_ladder_price_check_uses_anchor_shop_when_shop_is_not_passed(): void
    {
        // Глобальный админ в сетевом каталоге магазин не выбирает: ProductUpdate
        // подставляет якорный магазин сети.
        Cache::forever(NetworkCatalogMode::ANCHOR_CACHE_KEY, $this->shop->id);

        $variables = ['id' => $this->product->id, 'price_tiers' => [['min_quantity' => 2, 'value' => 249]]];

        $this->actingAs($this->user)->graphQL(self::UPDATE_TIERS_ONLY, $variables)
            ->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_PRICE_REQUIRED);

        $this->makePrice();

        $this->actingAs($this->user)->graphQL(self::UPDATE_TIERS_ONLY, $variables)->assertJsonMissing(['errors']);

        $this->assertSame(
            [['min_quantity' => 2.0, 'price' => 249.0]],
            $this->tiers->ladderFor($this->product->id, $this->shop->id)
        );
    }

    public function test_soft_deleted_price_row_is_not_a_price(): void
    {
        $this->makePrice()->delete();

        $this->actingAs($this->user)->graphQL(self::UPDATE_TIERS_ONLY, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_PRICE_REQUIRED);
    }

    public function test_product_create_with_ladder_and_without_price_creates_nothing(): void
    {
        Cache::forever(NetworkCatalogMode::ANCHOR_CACHE_KEY, $this->shop->id);
        $title = 'Товар без цены ' . uniqid();

        $this->actingAs($this->user)->graphQL(self::CREATE_WITHOUT_PRICE, [
            'title' => $title,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_PRICE_REQUIRED);

        $this->assertFalse(Product::where('title', $title)->exists(), 'отказ приходит до создания товара');
    }

    public function test_clearing_ladder_without_price_is_allowed_and_publishes_no_offer(): void
    {
        $this->seedLadder();

        $this->actingAs($this->user)->graphQL(self::UPDATE_TIERS_ONLY, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price_tiers' => [],
        ])->assertJsonMissing(['errors']);

        $this->assertSame([], $this->tiers->ladderFor($this->product->id, $this->shop->id));
        $this->assertSame(0, $this->priceUpdatedEvents($this->product->id), 'без цены оффера нет: событие с price = 0 не публикуется');
    }

    /**
     * F5 финального ревью: у сотрудника-партнёра без привязки к магазину
     * (Role::SHOP без строки в user_shops — битые данные, но встречающиеся)
     * ProductUpdate::resolveNetworkCatalogShop() не резолвит shop_id. Раньше
     * лесенка в этом случае молча не писалась (мутация отвечала 200 OK).
     * Явный отказ по ключу price_tiers — до создания товара.
     */
    public function test_ladder_argument_without_resolvable_shop_fails_explicitly(): void
    {
        $shopUser = User::create([
            'name' => 'Shop User ' . uniqid(),
            'email' => uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $shopUser->assignRole(Role::SHOP);
        // Намеренно без UserShop::create(): сотрудник не привязан ни к одному магазину.

        $title = 'Товар без магазина ' . uniqid();

        $this->actingAs($shopUser)->graphQL(self::CREATE_WITHOUT_PRICE, [
            'title' => $title,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'price_tiers' => [],
        ])->assertGraphQLValidationError('price_tiers', PriceTiersRule::MESSAGE_SHOP_REQUIRED);

        $this->assertFalse(Product::where('title', $title)->exists(), 'отказ приходит до создания товара');
    }

    public function test_service_guard_rejects_ladder_passed_around_the_validator(): void
    {
        $this->setPriceTiersModule(false);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->tiers->ladderFromArgs(['price_tiers' => [['min_quantity' => 2, 'value' => 249]]]);
    }
}
