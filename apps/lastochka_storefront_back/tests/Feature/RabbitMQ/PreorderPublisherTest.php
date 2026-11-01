<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Preorder;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PreorderPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_preorder_created_event_published_with_event_id_and_timestamp(): void
    {
        $store = Store::factory()->create(['code' => 'shop-test']);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'title' => 'Кабель тестовый',
            'sku' => 'CABLE-001',
        ]);

        $preorder = Preorder::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'full_name' => 'Иван Иванов',
            'phone' => '+79992223344',
            'email' => 'ivan@example.com',
            'quantity' => 3,
            'comment' => 'Прошу связаться',
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'preorders',
            'entity_id' => $preorder->id,
            'event_type' => 'created',
            'routing_key' => 'preorder.created',
        ]);

        $outbox = EventOutbox::where('entity_type', 'preorders')
            ->where('entity_id', $preorder->id)
            ->first();

        $this->assertNotNull($outbox);

        $payload = $outbox->payload;

        // Регрессия ELECTRO-HUB-10194: admin_back PreorderEventTransformer
        // требует event_id в requiredFields. Без него consumer ACKает
        // событие как Invalid и заявка не попадает в админку.
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertNotEmpty($payload['event_id']);
        $this->assertArrayHasKey('event_timestamp', $payload);
        $this->assertNotEmpty($payload['event_timestamp']);

        $this->assertEquals((string) $preorder->id, (string) $payload['id']);
        $this->assertEquals($user->id, $payload['user_id']);
        $this->assertEquals('+79991234567', $payload['user_phone']);
        $this->assertEquals($product->id, $payload['product_id']);
        $this->assertEquals('Кабель тестовый', $payload['product_title']);
        $this->assertEquals('CABLE-001', $payload['product_sku']);
        $this->assertEquals('shop-test', $payload['shop_code']);
        $this->assertEquals('Иван Иванов', $payload['full_name']);
        $this->assertEquals('+79992223344', $payload['phone']);
        $this->assertEquals('ivan@example.com', $payload['email']);
        $this->assertEquals(3, $payload['quantity']);
        $this->assertEquals('Прошу связаться', $payload['comment']);
        $this->assertEquals('new', $payload['status']);
    }
}
