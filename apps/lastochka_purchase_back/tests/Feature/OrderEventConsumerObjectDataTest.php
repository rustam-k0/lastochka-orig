<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientOrderInfo;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Тест: consumer корректно обрабатывает поле object_data.
 *
 * Проверяемый метод: OrderEventTransformer::processClientOrderInfo (protected)
 * — вызывается из afterCreate/afterUpdate при наличии address или client_info в payload.
 */
final class OrderEventConsumerObjectDataTest extends TestCase
{
    use DatabaseTransactions;

    private int $clientId;
    private int $shopId;

    protected function setUp(): void
    {
        parent::setUp();

        // Создаём минимальные seed-записи внутри транзакции DatabaseTransactions
        $this->clientId = (int) DB::table('clients')->insertGetId([
            'password' => bcrypt('test'),
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'is_b2b' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = (int) DB::table('shops')->insertGetId([
            'title' => 'Test Shop',
            'id_1c' => 0,
            'default' => 0,
            'slot_max_orders' => 2,
            'slot_time' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Создать тестовый Order с минимальными полями.
     */
    private function makeOrder(): Order
    {
        return Order::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->clientId,
            'shop_id' => $this->shopId,
            'type_id' => 2,   // RECEIVE_DELIVERY
            'status_id' => 1, // ORDER_CREATED
            'payment_type_id' => 1,
            'amount' => 1000,
            'origin_amount' => 1000,
            'slot_start_date' => now(),
            'slot_end_date' => now()->addHour(),
        ]);
    }

    /**
     * Вызвать protected метод processClientOrderInfo через Reflection.
     */
    private function callProcessClientOrderInfo(
        OrderEventTransformer $transformer,
        Order $order,
        array $eventData
    ): void {
        $method = new ReflectionMethod(OrderEventTransformer::class, 'processClientOrderInfo');
        $method->setAccessible(true);
        $method->invoke($transformer, $order, $eventData);
    }

    /**
     * Тест 1: consumer сохраняет object_data когда оно есть в payload.
     */
    public function test_consumer_saves_object_data_from_payload(): void
    {
        $order = $this->makeOrder();

        $eventData = [
            'client_info' => [
                'name' => 'Test',
                'phone' => '+79991234567',
                'email' => 'test@example.com',
            ],
            'address' => [
                'address' => 'г. Каспийск, ул. Горького, д. 78А',
                'latitude' => '42.89',
                'longitude' => '47.63',
                'entrance' => '3',
                'floor' => '10',
                'flat' => '123',
                'object_data' => [
                    'name' => 'Многоэтажка',
                    'contact_name' => 'Иванов И.И.',
                    'contact_phone' => '+79991234567',
                    'has_lift' => true,
                    'needs_pass' => true,
                    'photos' => [
                        ['url' => 'https://example.test/a.jpg', 'mime' => 'image/jpeg'],
                    ],
                ],
            ],
        ];

        $transformer = app(OrderEventTransformer::class);
        $this->callProcessClientOrderInfo($transformer, $order, $eventData);

        $info = ClientOrderInfo::where('order_id', $order->id)->first();

        $this->assertNotNull($info, 'ClientOrderInfo должен быть создан');

        // Читаем сырой JSON минуя accessor (accessor делает camelCase маппинг)
        $rawJson = $info->getRawOriginal('object_data');
        $raw = is_array($rawJson) ? $rawJson : json_decode((string) $rawJson, true);

        $this->assertIsArray($raw, 'object_data должен быть массивом');
        $this->assertSame('Многоэтажка', $raw['name'] ?? null);
        $this->assertTrue($raw['has_lift'] ?? false);
        $this->assertTrue($raw['needs_pass'] ?? false);
        $this->assertCount(1, $raw['photos'] ?? []);
    }

    /**
     * Тест 2: consumer НЕ перезаписывает существующий object_data когда поле отсутствует в payload.
     */
    public function test_consumer_preserves_existing_object_data_when_payload_field_absent(): void
    {
        $order = $this->makeOrder();

        // Создаём ClientOrderInfo с уже сохранённым object_data
        ClientOrderInfo::withoutEvents(function () use ($order) {
            ClientOrderInfo::create([
                'order_id' => $order->id,
                'name' => 'Имя',
                'email' => 'test@example.com',
                'object_data' => ['name' => 'Старый объект', 'has_lift' => false],
            ]);
        });

        // payload без object_data — consumer не должен его перезаписать
        $eventData = [
            'client_info' => [
                'name' => 'Обновлённое Имя',
                'email' => 'updated@example.com',
            ],
            'address' => [
                'address' => 'Новый адрес, д. 1',
                'entrance' => '2',
                // НЕТ ключа object_data — conditional set должен пропустить поле
            ],
        ];

        $transformer = app(OrderEventTransformer::class);
        $this->callProcessClientOrderInfo($transformer, $order, $eventData);

        $info = ClientOrderInfo::where('order_id', $order->id)->first();

        $this->assertNotNull($info, 'ClientOrderInfo должен существовать');

        $rawJson = $info->getRawOriginal('object_data');
        $raw = is_array($rawJson) ? $rawJson : json_decode((string) $rawJson, true);

        $this->assertIsArray($raw, 'object_data должен остаться массивом (не затёрт null)');
        $this->assertSame('Старый объект', $raw['name'] ?? null, 'Старый object_data.name должен сохраниться');
        $this->assertFalse($raw['has_lift'] ?? true, 'Старый object_data.has_lift должен сохраниться');
    }
}
