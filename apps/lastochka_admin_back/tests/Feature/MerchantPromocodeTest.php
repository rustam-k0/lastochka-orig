<?php

namespace Tests\Feature;

use App\Models\MerchantPromocode;
use App\Models\Shop;
use App\Models\User;
use App\Transformers\MerchantPromocodeEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantPromocodeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        $this->shop = Shop::create([
            'title' => 'Test Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    /** @test */
    public function it_can_create_platform_wide_promocode_without_shop_id(): void
    {
        $mutation = '
            mutation createMerchantPromocode(
                $scope: MerchantPromocodeScope
                $title: String!
                $code: String!
                $discount_type: MerchantPromocodeDiscountType!
                $discount_value: Int!
                $valid_from: Date!
                $valid_to: Date!
                $binding_type: MerchantPromocodeBindingType!
            ) {
                createMerchantPromocode(input: {
                    scope: $scope
                    title: $title
                    code: $code
                    discount_type: $discount_type
                    discount_value: $discount_value
                    valid_from: $valid_from
                    valid_to: $valid_to
                    binding_type: $binding_type
                }) {
                    id
                    scope
                    shop_id
                    code
                    discount_value
                }
            }
        ';

        $code = 'HUBWIDE' . uniqid();

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'scope' => 'PLATFORM',
                'title' => 'Platform-wide скидка',
                'code' => $code,
                'discount_type' => 'PERCENT',
                'discount_value' => 15,
                'valid_from' => now()->format('Y-m-d'),
                'valid_to' => now()->addDays(30)->format('Y-m-d'),
                'binding_type' => 'ALL',
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantPromocode' => [
                    'scope' => 'platform',
                    'shop_id' => null,
                    'code' => strtoupper($code),
                    'discount_value' => 15,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_promocodes', [
            'scope' => 'platform',
            'shop_id' => null,
            'code' => strtoupper($code),
        ]);
    }

    /** @test */
    public function it_rejects_store_scoped_promocode_without_shop_id(): void
    {
        $mutation = '
            mutation createMerchantPromocode(
                $title: String!
                $code: String!
                $discount_type: MerchantPromocodeDiscountType!
                $discount_value: Int!
                $valid_from: Date!
                $valid_to: Date!
                $binding_type: MerchantPromocodeBindingType!
            ) {
                createMerchantPromocode(input: {
                    title: $title
                    code: $code
                    discount_type: $discount_type
                    discount_value: $discount_value
                    valid_from: $valid_from
                    valid_to: $valid_to
                    binding_type: $binding_type
                }) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'title' => 'Без магазина',
                'code' => 'NOSHOP' . uniqid(),
                'discount_type' => 'PERCENT',
                'discount_value' => 10,
                'valid_from' => now()->format('Y-m-d'),
                'valid_to' => now()->addDays(10)->format('Y-m-d'),
                'binding_type' => 'ALL',
            ]);

        $response->assertGraphQLValidationError('input.shop_id', 'ID магазина обязателен');
    }

    /** @test */
    public function it_can_update_scope_from_store_to_platform(): void
    {
        $promocode = MerchantPromocode::create([
            'shop_id' => $this->shop->id,
            'scope' => MerchantPromocode::SCOPE_STORE,
            'title' => 'Store scope',
            'code' => 'STORESCOPE' . uniqid(),
            'discount_type' => 'percent',
            'discount_percent' => 20,
            'discount_value' => 20,
            'binding_type' => 'all',
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addDays(15)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $mutation = '
            mutation updateMerchantPromocode($id: Int!, $scope: MerchantPromocodeScope) {
                updateMerchantPromocode(input: {
                    id: $id
                    scope: $scope
                }) {
                    id
                    scope
                    shop_id
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $promocode->id,
                'scope' => 'PLATFORM',
            ]);

        $response->assertJson([
            'data' => [
                'updateMerchantPromocode' => [
                    'id' => $promocode->id,
                    'scope' => 'platform',
                    'shop_id' => null,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_promocodes', [
            'id' => $promocode->id,
            'scope' => 'platform',
            'shop_id' => null,
        ]);
    }

    /** @test */
    public function update_allows_clearing_max_uses_to_null_while_keeping_per_client_limit(): void
    {
        // Кейс: у промокода max_uses=10, max_uses_per_client=5.
        // Админ снимает общий лимит (max_uses: null), per-client оставляет прежний.
        // Валидатор должен различать «ключ не передан» (использовать текущее значение модели)
        // от «передан null» (явное снятие лимита → не применять per-client <= max_uses).
        $promocode = MerchantPromocode::create([
            'shop_id' => $this->shop->id,
            'scope' => MerchantPromocode::SCOPE_STORE,
            'title' => 'Limit test',
            'code' => 'LIMIT' . uniqid(),
            'discount_type' => 'percent',
            'discount_percent' => 10,
            'discount_value' => 10,
            'binding_type' => 'all',
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
            'max_uses' => 10,
            'max_uses_per_client' => 5,
        ]);

        $mutation = '
            mutation updateMerchantPromocode($id: Int!, $max_uses: Int) {
                updateMerchantPromocode(input: {
                    id: $id
                    max_uses: $max_uses
                }) {
                    id
                    max_uses
                    max_uses_per_client
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $promocode->id,
                'max_uses' => null,
            ]);

        $response->assertJson([
            'data' => [
                'updateMerchantPromocode' => [
                    'id' => $promocode->id,
                    'max_uses' => null,
                    'max_uses_per_client' => 5,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_promocodes', [
            'id' => $promocode->id,
            'max_uses' => null,
            'max_uses_per_client' => 5,
        ]);
    }

    /** @test */
    public function transformer_event_payload_contains_scope(): void
    {
        // Создаём промокод с updated_at в прошлом, чтобы отличить от now().
        $pastTimestamp = now()->subHour();

        $promocode = MerchantPromocode::create([
            'shop_id' => null,
            'scope' => MerchantPromocode::SCOPE_PLATFORM,
            'title' => 'Platform promo',
            'code' => 'TRPROMO' . uniqid(),
            'discount_type' => 'percent',
            'discount_percent' => 12,
            'discount_value' => 12,
            'binding_type' => 'all',
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addDays(10)->format('Y-m-d'),
            'is_active' => true,
        ]);

        // Принудительно сдвигаем updated_at в прошлое.
        $promocode->timestamps = false;
        $promocode->updated_at = $pastTimestamp;
        $promocode->save();
        $promocode->timestamps = true;

        $transformer = new MerchantPromocodeEventTransformer();
        $event = $transformer->toEvent($promocode->fresh());

        $this->assertArrayHasKey('scope', $event);
        $this->assertSame('platform', $event['scope']);
        $this->assertArrayHasKey('event_timestamp', $event);

        // pitfall #9: event_timestamp должен быть now(), не updated_at.
        $this->assertNotSame(
            $pastTimestamp->toIso8601String(),
            $event['event_timestamp'],
            'event_timestamp должен быть now(), не updated_at модели'
        );

        // И должен быть близок к now() (в пределах 1 минуты).
        $eventTime = \Carbon\Carbon::parse($event['event_timestamp']);
        $this->assertLessThanOrEqual(60, abs(now()->diffInSeconds($eventTime)));
    }

    /** @test */
    public function transformer_from_event_reads_scope(): void
    {
        $transformer = new MerchantPromocodeEventTransformer();

        $attrs = $transformer->fromEvent([
            'scope' => 'platform',
            'title' => 'Test',
            'code' => 'FROMEVENT',
            'discount_percent' => 5,
            'valid_from' => now()->toIso8601String(),
            'valid_to' => now()->addDays(5)->toIso8601String(),
            'is_active' => true,
        ]);

        $this->assertSame('platform', $attrs['scope']);
    }

    /** @test */
    public function transformer_from_event_defaults_scope_to_store_when_absent(): void
    {
        $transformer = new MerchantPromocodeEventTransformer();

        $attrs = $transformer->fromEvent([
            'title' => 'Legacy event',
            'code' => 'LEGACY',
            'discount_percent' => 5,
        ]);

        $this->assertSame('store', $attrs['scope']);
    }

    /** @test */
    public function it_can_create_promocode_with_code_of_soft_deleted_one(): void
    {
        $deleted = $this->makeStorePromocode('ЛАСТОЧКА');
        $deleted->delete();

        $response = $this->actingAs($this->user)
            ->graphQL($this->createStoreMutation(), $this->createStoreVariables('ЛАСТОЧКА'));

        $response->assertJsonMissingPath('errors');
        $this->assertSame('ЛАСТОЧКА', $response->json('data.createMerchantPromocode.code'));
        $this->assertNull(MerchantPromocode::withTrashed()->find($deleted->id), 'Удалённая запись должна освободить код');
    }

    /** @test */
    public function it_still_rejects_code_of_active_promocode_in_same_shop(): void
    {
        $this->makeStorePromocode('ЛАСТОЧКА');

        $response = $this->actingAs($this->user)
            ->graphQL($this->createStoreMutation(), $this->createStoreVariables('ЛАСТОЧКА'));

        $this->assertStringContainsString(
            'Промокод с таким кодом уже существует',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(1, MerchantPromocode::where('shop_id', $this->shop->id)->where('code', 'ЛАСТОЧКА')->count());
    }

    /** @test */
    public function it_uppercases_cyrillic_code_on_create(): void
    {
        $response = $this->actingAs($this->user)
            ->graphQL($this->createStoreMutation(), $this->createStoreVariables(' ласточка '));

        $response->assertJsonMissingPath('errors');
        $this->assertSame('ЛАСТОЧКА', $response->json('data.createMerchantPromocode.code'));
    }

    /** @test */
    public function it_can_rename_promocode_to_code_of_soft_deleted_one(): void
    {
        $deleted = $this->makeStorePromocode('ЛАСТОЧКА');
        $deleted->delete();
        $promocode = $this->makeStorePromocode('ДРУГОЙ');

        $response = $this->actingAs($this->user)->graphQL('
            mutation updateMerchantPromocode($id: Int!, $code: String) {
                updateMerchantPromocode(input: { id: $id, code: $code }) {
                    id
                    code
                }
            }
        ', ['id' => $promocode->id, 'code' => 'ласточка']);

        $response->assertJsonMissingPath('errors');
        $this->assertSame('ЛАСТОЧКА', $response->json('data.updateMerchantPromocode.code'));
        $this->assertNull(MerchantPromocode::withTrashed()->find($deleted->id));
    }

    private function makeStorePromocode(string $code): MerchantPromocode
    {
        return MerchantPromocode::create([
            'shop_id' => $this->shop->id,
            'scope' => MerchantPromocode::SCOPE_STORE,
            'title' => 'Промокод ' . $code,
            'code' => $code,
            'discount_type' => 'fixed',
            'discount_value' => 300,
            'discount_percent' => 0,
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addDays(14)->format('Y-m-d'),
            'binding_type' => 'all',
        ]);
    }

    private function createStoreMutation(): string
    {
        return '
            mutation createMerchantPromocode(
                $shop_id: Int
                $title: String!
                $code: String!
                $discount_type: MerchantPromocodeDiscountType!
                $discount_value: Int!
                $valid_from: Date!
                $valid_to: Date!
                $binding_type: MerchantPromocodeBindingType!
            ) {
                createMerchantPromocode(input: {
                    scope: STORE
                    shop_id: $shop_id
                    title: $title
                    code: $code
                    discount_type: $discount_type
                    discount_value: $discount_value
                    valid_from: $valid_from
                    valid_to: $valid_to
                    binding_type: $binding_type
                }) {
                    id
                    code
                }
            }
        ';
    }

    private function createStoreVariables(string $code): array
    {
        return [
            'shop_id' => $this->shop->id,
            'title' => 'Ласточка',
            'code' => $code,
            'discount_type' => 'FIXED',
            'discount_value' => 300,
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addDays(14)->format('Y-m-d'),
            'binding_type' => 'ALL',
        ];
    }
}
