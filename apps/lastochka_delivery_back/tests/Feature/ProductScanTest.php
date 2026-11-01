<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\ProductScan;
use App\GraphQL\Validators\Assembly\ProductScanValidator;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Фиксация скана товара при сборке (LASTOCHKI-JAMI-11595, #11600).
 *
 * Мутация ProductScan публикует picking.item_scanned (method qr|manual,
 * result ok|error) через transactional outbox — источник метрик
 * «точность сборки» и «% QR» в B2E-аналитике.
 */
class ProductScanTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $collectorId;
    private int $shiftId;
    private int $orderId;
    private int $productId;
    private int $assemblyId;
    private int $assemblyItemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Product scan shop',
            'code' => 'product-scan-shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->collectorId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shiftId = \DB::table('shifts')->insertGetId([
            'user_id' => $this->collectorId,
            'shop_id' => $this->shopId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = \DB::table('products')->insertGetId([
            'title' => 'Кефир 1л',
            'articul' => 'SCAN-' . uniqid(),
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'scan-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $this->orderId,
            'product_id' => $this->productId,
            'quantity' => 1,
            'price' => 100,
            'price_during_order' => 100,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'is_supplemented' => 0,
            'is_eligible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $this->orderId,
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assemblyItemId = \DB::table('assembly_items')->insertGetId([
            'assembly_id' => $this->assemblyId,
            'order_item_id' => $orderItemId,
            'status_id' => Status::PRODUCT_WAIT,
            'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
            'quantity' => 1,
            'price' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function latestOutbox(string $routingKey): ?object
    {
        return \DB::table('event_outbox')
            ->where('routing_key', $routingKey)
            ->orderByDesc('id')
            ->first();
    }

    public function test_qr_scan_publishes_item_scanned_event(): void
    {
        $this->actingAs(User::find($this->collectorId), 'api');

        $result = (new ProductScan())->__invoke(null, [
            'assembly_id' => $this->assemblyId,
            'assembly_item_id' => $this->assemblyItemId,
            'product_id' => $this->productId,
            'barcode' => '4600000000001',
            'method' => 'qr',
            'result' => 'ok',
        ]);

        $this->assertSame(200, $result['code']);

        $row = $this->latestOutbox('picking.item_scanned');
        $this->assertNotNull($row, 'Событие picking.item_scanned не попало в outbox');

        $payload = json_decode($row->payload, true);
        $orderUuid = \DB::table('orders')->where('id', $this->orderId)->value('uuid');

        $this->assertSame($orderUuid, $payload['order_id']);
        $this->assertSame($orderUuid, $payload['correlation_id']);
        $this->assertSame($this->collectorId, $payload['picker_id']);
        $this->assertSame('shift-' . $this->shiftId, $payload['session_id']);
        $this->assertSame($this->productId, $payload['product_id']);
        $this->assertSame('Кефир 1л', $payload['product_title']);
        $this->assertSame($this->assemblyItemId, $payload['assembly_item_id']);
        $this->assertSame('qr', $payload['method']);
        $this->assertSame('ok', $payload['result']);
        $this->assertSame('4600000000001', $payload['barcode']);
        $this->assertSame('product-scan-shop', $payload['shop_code']);
    }

    public function test_manual_error_scan_without_product_is_accepted(): void
    {
        $this->actingAs(User::find($this->collectorId), 'api');

        $result = (new ProductScan())->__invoke(null, [
            'assembly_id' => $this->assemblyId,
            'method' => 'manual',
            'result' => 'error',
        ]);

        $this->assertSame(200, $result['code']);

        $payload = json_decode($this->latestOutbox('picking.item_scanned')->payload, true);

        $this->assertSame('manual', $payload['method']);
        $this->assertSame('error', $payload['result']);
        $this->assertNull($payload['product_id']);
        $this->assertNull($payload['assembly_item_id']);
        $this->assertNull($payload['barcode']);
    }

    public function test_validator_rejects_unknown_method_and_result(): void
    {
        $rules = (new ProductScanValidator())->rules();

        $fails = Validator::make([
            'assembly_id' => $this->assemblyId,
            'method' => 'nfc',
            'result' => 'maybe',
        ], $rules)->fails();

        $this->assertTrue($fails, 'method=nfc / result=maybe должны быть отвергнуты');

        $passes = Validator::make([
            'assembly_id' => $this->assemblyId,
            'method' => 'qr',
            'result' => 'ok',
        ], $rules)->passes();

        $this->assertTrue($passes);
    }
}
