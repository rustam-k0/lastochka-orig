<?php

namespace Tests\Feature;

use App\Enums\ProductGroupFillType;
use App\Jobs\MerchantMultiShop\CreateMerchantEntityCopiesJob;
use App\Models\MerchantProductGroup;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\MerchantProductGroupService;
use App\Transformers\MerchantProductGroupEventTransformer;
use Carbon\Carbon;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Способ наполнения товарной группы (fill_type): ручной состав или
 * автоматический критерий, товары которого собирает витрина.
 */
class MerchantProductGroupFillTypeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;
    protected Product $product1;
    protected Product $product2;

    private const CREATE_MUTATION = '
        mutation CreateMerchantProductGroup($input: CreateMerchantProductGroupInput!) {
            createMerchantProductGroup(input: $input) {
                id
                fill_type
                products_count
            }
        }
    ';

    private const UPDATE_MUTATION = '
        mutation UpdateMerchantProductGroup($id: Int!, $input: UpdateMerchantProductGroupInput!) {
            updateMerchantProductGroup(id: $id, input: $input) {
                id
                fill_type
                products_count
            }
        }
    ';

    protected function setUp(): void
    {
        // Пакет регистрирует eloquent-observers один раз на PHP-процесс
        // (static-флаг), а PHPUnit пересоздаёт приложение на каждый тест —
        // сбрасываем флаг до boot, чтобы outbox-observer работал в каждом тесте.
        $flag = new \ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered');
        $flag->setAccessible(true);
        $flag->setValue(null, false);

        parent::setUp();

        $this->user = $this->adminUser();
        $this->shop = $this->createShop();
        $this->product1 = $this->createProduct($this->shop->id);
        $this->product2 = $this->createProduct($this->shop->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createShop(): Shop
    {
        return Shop::create([
            'title' => 'Fill Type Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    private function createProduct(int $shopId): Product
    {
        return Product::create([
            'title' => 'Fill Type Product ' . uniqid(),
            'shop_id' => $shopId,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
    }

    /** @test */
    public function enum_lists_all_fill_types(): void
    {
        $this->assertSame(
            ['manual', 'purchase_history', 'viewed_not_bought', 'is_new', 'is_hurry_up'],
            ProductGroupFillType::values()
        );
    }

    /** @test */
    public function group_defaults_to_manual_fill_type(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::CREATE_MUTATION, [
            'input' => [
                'shop_id' => $this->shop->id,
                'title' => 'Ручная группа ' . uniqid(),
                'product_ids' => [$this->product1->id, $this->product2->id],
            ],
        ]);

        $response->assertJsonPath('data.createMerchantProductGroup.fill_type', 'manual');
        $response->assertJsonPath('data.createMerchantProductGroup.products_count', 2);

        $this->assertDatabaseHas('merchant_product_groups', [
            'id' => $response->json('data.createMerchantProductGroup.id'),
            'fill_type' => 'manual',
        ]);
    }

    /** @test */
    public function creating_auto_group_ignores_product_ids(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::CREATE_MUTATION, [
            'input' => [
                'shop_id' => $this->shop->id,
                'title' => 'Новинки ' . uniqid(),
                'fill_type' => 'is_new',
                'product_ids' => [$this->product1->id],
                'products_with_qty' => [['product_id' => $this->product2->id, 'quantity' => 2]],
            ],
        ]);

        $response->assertJsonPath('data.createMerchantProductGroup.fill_type', 'is_new');
        $response->assertJsonPath('data.createMerchantProductGroup.products_count', 0);

        $this->assertDatabaseMissing('merchant_product_group_items', [
            'merchant_product_group_id' => $response->json('data.createMerchantProductGroup.id'),
        ]);
    }

    /** @test */
    public function creating_auto_group_skips_product_shop_ownership_check(): void
    {
        $foreignProduct = $this->createProduct($this->createShop()->id);

        $response = $this->actingAs($this->user)->graphQL(self::CREATE_MUTATION, [
            'input' => [
                'shop_id' => $this->shop->id,
                'title' => 'Успей купить ' . uniqid(),
                'fill_type' => 'is_hurry_up',
                'product_ids' => [$foreignProduct->id],
            ],
        ]);

        $response->assertJsonPath('data.createMerchantProductGroup.fill_type', 'is_hurry_up');
        $response->assertJsonPath('data.createMerchantProductGroup.products_count', 0);
    }

    /** @test */
    public function create_rejects_unknown_fill_type(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::CREATE_MUTATION, [
            'input' => [
                'shop_id' => $this->shop->id,
                'title' => 'Неверный тип ' . uniqid(),
                'fill_type' => 'random',
            ],
        ]);

        $response->assertGraphQLValidationKeys(['input.fill_type']);
    }

    /** @test */
    public function update_rejects_unknown_fill_type(): void
    {
        $group = MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Группа ' . uniqid(),
        ]);

        $response = $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, [
            'id' => $group->id,
            'input' => ['fill_type' => 'bestsellers'],
        ]);

        $response->assertGraphQLValidationKeys(['input.fill_type']);
        $this->assertSame('manual', $group->fresh()->fill_type);
    }

    /** @test */
    public function switching_to_auto_fill_type_clears_products(): void
    {
        $group = MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Группа ' . uniqid(),
        ]);
        $group->syncProducts([$this->product1->id, $this->product2->id]);

        $response = $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, [
            'id' => $group->id,
            'input' => [
                'fill_type' => 'purchase_history',
                'product_ids' => [$this->product1->id],
            ],
        ]);

        $response->assertJsonPath('data.updateMerchantProductGroup.fill_type', 'purchase_history');
        $response->assertJsonPath('data.updateMerchantProductGroup.products_count', 0);
        $this->assertSame(0, $group->products()->count());
    }

    /** @test */
    public function auto_group_ignores_product_ids_on_update_without_fill_type(): void
    {
        $group = MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Смотрел ' . uniqid(),
            'fill_type' => 'viewed_not_bought',
        ]);

        $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, [
            'id' => $group->id,
            'input' => ['product_ids' => [$this->product1->id]],
        ])->assertJsonPath('data.updateMerchantProductGroup.products_count', 0);

        $this->assertSame('viewed_not_bought', $group->fresh()->fill_type);
    }

    /** @test */
    public function switching_back_to_manual_accepts_products(): void
    {
        $group = MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Новинки ' . uniqid(),
            'fill_type' => 'is_new',
        ]);

        $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, [
            'id' => $group->id,
            'input' => [
                'fill_type' => 'manual',
                'product_ids' => [$this->product1->id, $this->product2->id],
            ],
        ])
            ->assertJsonPath('data.updateMerchantProductGroup.fill_type', 'manual')
            ->assertJsonPath('data.updateMerchantProductGroup.products_count', 2);
    }

    /** @test */
    public function to_event_contains_fill_type(): void
    {
        $group = MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Успей купить ' . uniqid(),
            'fill_type' => 'is_hurry_up',
        ]);

        $payload = app(MerchantProductGroupEventTransformer::class)->toEvent($group->fresh());

        $this->assertSame('is_hurry_up', $payload['fill_type']);
        $this->assertSame([], $payload['products']);
    }

    /** @test */
    public function to_event_defaults_fill_type_to_manual(): void
    {
        $group = MerchantProductGroup::create([
            'shop_id' => $this->shop->id,
            'title' => 'Ручная ' . uniqid(),
        ]);

        $payload = app(MerchantProductGroupEventTransformer::class)->toEvent($group->fresh());

        $this->assertSame('manual', $payload['fill_type']);
    }

    /**
     * Попутный фикс: createForShop после syncProducts делает touch() для любой
     * группы. Событие created пишется до pivot sync (пустые products), а
     * updated после touch() несёт актуальный состав.
     *
     * @test
     */
    public function create_for_shop_publishes_updated_event_with_products_for_manual_group(): void
    {
        $this->actingAs($this->user);

        // touch() в ту же секунду, что и create(), не меняет updated_at и не
        // стреляет updated — сдвигаем часы сразу после события created.
        Carbon::setTestNow(now()->subMinutes(5));
        MerchantProductGroup::created(function () {
            Carbon::setTestNow(now()->addMinute());
        });

        $group = (new MerchantProductGroupService())->createForShop([
            'title' => 'Ручная ' . uniqid(),
            'product_ids' => [$this->product1->id, $this->product2->id],
        ], $this->shop->id);

        $events = EventOutbox::where('entity_type', 'merchant_product_groups')
            ->where('entity_id', $group->id)
            ->orderBy('id')
            ->get();

        $created = $events->firstWhere('event_type', 'created');
        $updated = $events->where('event_type', 'updated')->last();

        $this->assertNotNull($created, 'событие created должно быть в outbox');
        $this->assertNotNull($updated, 'после syncProducts нужен touch(), публикующий updated');
        $this->assertSame('manual', $updated->payload['fill_type']);
        $this->assertCount(2, $updated->payload['products']);
    }

    /** @test */
    public function create_mutation_outbox_payload_contains_fill_type(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::CREATE_MUTATION, [
            'input' => [
                'shop_id' => $this->shop->id,
                'title' => 'Новинки ' . uniqid(),
                'fill_type' => 'is_new',
            ],
        ]);

        $groupId = $response->json('data.createMerchantProductGroup.id');

        $event = EventOutbox::where('entity_type', 'merchant_product_groups')
            ->where('entity_id', $groupId)
            ->where('event_type', 'created')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('is_new', $event->payload['fill_type']);
        $this->assertSame([], $event->payload['products']);
    }

    /** @test */
    public function copies_to_other_shops_inherit_fill_type(): void
    {
        Queue::fake();

        $secondShop = $this->createShop();
        $title = 'Новинки сети ' . uniqid();

        $job = new CreateMerchantEntityCopiesJob(
            CreateMerchantEntityCopiesJob::ENTITY_PRODUCT_GROUP,
            ['title' => $title, 'fill_type' => 'is_new', 'product_ids' => [$this->product1->id]],
            [$this->shop->id, $secondShop->id],
            false,
            $this->shop->id,
        );
        $job->handle();

        $copy = MerchantProductGroup::where('shop_id', $secondShop->id)->where('title', $title)->firstOrFail();

        $this->assertSame('is_new', $copy->fill_type);
        $this->assertSame(0, $copy->products()->count());
    }
}
