<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductReadyTimeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected Shop $shop;

    protected function setUp(): void
    {
        // static-флаг пакета переживает пересоздание app между тестами —
        // без сброса до создания приложения observers регистрируются только
        // в первом тесте процесса и события не пишутся в outbox.
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->shop = Shop::firstOrFail();
    }

    private const UPDATE_MUTATION = '
        mutation ($id: Int, $shop_id: Int, $price: Float, $ready_fixed_time: String) {
            ProductUpdate(
                id: $id,
                shop_id: $shop_id,
                price: $price,
                ready_fixed_time: $ready_fixed_time
            ) {
                code
                message
            }
        }
    ';

    private function createProduct(): Product
    {
        // Product::factory()->create() тянет за собой Shop::factory() —
        // у Shop нет HasFactory trait, поэтому строим Product вручную
        // (тот же паттерн, что в ProductCategory1cFieldTest).
        $product = new Product();
        $product->shop_id = $this->shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->save();

        return $product;
    }

    /** @test */
    public function it_saves_ready_fixed_time()
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $product->id,
                'shop_id' => $this->shop->id,
                'price' => 100,
                'ready_fixed_time' => '12:00',
            ])
            ->assertJsonMissing(['errors']);

        $this->assertStringStartsWith('12:00', (string) $product->fresh()->ready_fixed_time);
    }

    /** @test */
    public function null_clears_ready_fixed_time()
    {
        $product = $this->createProduct();
        $product->update(['ready_fixed_time' => '12:00']);

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $product->id,
                'shop_id' => $this->shop->id,
                'price' => 100,
                'ready_fixed_time' => null,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertNull($product->fresh()->ready_fixed_time);
    }

    /** @test */
    public function invalid_time_format_is_rejected()
    {
        $product = $this->createProduct();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $product->id,
                'shop_id' => $this->shop->id,
                'price' => 100,
                'ready_fixed_time' => 'вечером',
            ]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNull($product->fresh()->ready_fixed_time);
    }

    /** @test */
    public function ready_fixed_time_is_published_to_outbox_payload()
    {
        $product = $this->createProduct();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $product->id,
                'shop_id' => $this->shop->id,
                'price' => 100,
                'ready_fixed_time' => '12:00',
            ])
            ->assertJsonMissing(['errors']);

        $payload = \DB::table('event_outbox')
            ->where('entity_type', 'products')
            ->where('entity_id', $product->id)
            ->orderByDesc('id')
            ->value('payload');

        $this->assertNotNull($payload);
        $decoded = json_decode($payload, true);
        $this->assertSame('12:00', $decoded['ready_fixed_time']);
        $this->assertArrayNotHasKey('ready_time_mode', $decoded);
        $this->assertArrayNotHasKey('ready_lag_minutes', $decoded);
    }

    /** @test */
    public function reset_publishes_null_ready_fixed_time_to_outbox()
    {
        $product = $this->createProduct();
        $product->update(['ready_fixed_time' => '12:00']);

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $product->id,
                'shop_id' => $this->shop->id,
                'price' => 100,
                'ready_fixed_time' => null,
            ])
            ->assertJsonMissing(['errors']);

        $payload = \DB::table('event_outbox')
            ->where('entity_type', 'products')
            ->where('entity_id', $product->id)
            ->orderByDesc('id')
            ->value('payload');

        $this->assertNotNull($payload);
        $decoded = json_decode($payload, true);
        $this->assertArrayHasKey('ready_fixed_time', $decoded);
        $this->assertNull($decoded['ready_fixed_time']);
    }
}
