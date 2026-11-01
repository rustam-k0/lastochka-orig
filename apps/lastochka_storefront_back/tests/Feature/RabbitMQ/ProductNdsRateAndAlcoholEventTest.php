<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductNdsRateAndAlcoholEventTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
    }

    private function event(array $overrides): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->subMinutes(10)->toIso8601String(),
            'id' => 'prod_'.Str::random(6),
            'source' => '1c',
            'event_source' => '1c',
            'shop_code' => 'NDS_STORE',
            'sku' => 'SKU-'.Str::random(6),
            'title' => 'Товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }

    public function test_nds_rate_and_is_alcohol_are_applied(): void
    {
        $store = Store::factory()->create(['code' => 'NDS_STORE']);

        $this->assertSame('ack', $this->consumer->processEvent($this->event([
            'sku' => 'VODKA-1',
            'nds_rate' => '22%',
            'is_alcohol' => true,
        ])));

        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'VODKA-1',
            'vat_rate' => 11,
            'adult_only' => true,
            'pickup_only' => true,
        ]);
    }

    public function test_is_alcohol_false_clears_flags_and_unrecognized_rate_keeps_nds_code(): void
    {
        $store = Store::factory()->create(['code' => 'NDS_STORE']);
        $id = 'prod_'.Str::random(6);

        $this->consumer->processEvent($this->event(['id' => $id, 'sku' => 'JUICE-1', 'nds' => 3, 'is_alcohol' => true]));
        $this->consumer->processEvent($this->event([
            'id' => $id,
            'event_type' => 'updated',
            'event_timestamp' => now()->subMinutes(5)->toIso8601String(),
            'sku' => 'JUICE-1',
            'nds' => 3,
            'nds_rate' => '5%',
            'is_alcohol' => false,
        ]));

        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'JUICE-1',
            'vat_rate' => 3,
            'adult_only' => false,
            'pickup_only' => false,
        ]);
    }

    /**
     * Создать товар с is_alcohol=$initial и обновить его значением $value.
     */
    private function createThenUpdateAlcohol(string $sku, mixed $initial, mixed $value): void
    {
        $id = 'prod_'.Str::random(6);

        $this->consumer->processEvent($this->event(['id' => $id, 'sku' => $sku, 'is_alcohol' => $initial]));
        $this->consumer->processEvent($this->event([
            'id' => $id,
            'event_type' => 'updated',
            'event_timestamp' => now()->subMinutes(5)->toIso8601String(),
            'sku' => $sku,
            'is_alcohol' => $value,
        ]));
    }

    public function test_unrecognized_is_alcohol_keeps_flags_and_warns(): void
    {
        $store = Store::factory()->create(['code' => 'NDS_STORE']);

        foreach (['Да', 'Y'] as $i => $value) {
            $sku = 'BEER-'.$i;
            $id = 'prod_'.Str::random(6);
            $this->consumer->processEvent($this->event(['id' => $id, 'sku' => $sku, 'is_alcohol' => true]));

            Log::spy();
            $this->consumer->processEvent($this->event([
                'id' => $id,
                'event_type' => 'updated',
                'event_timestamp' => now()->subMinutes(5)->toIso8601String(),
                'sku' => $sku,
                'is_alcohol' => $value,
            ]));

            $this->assertDatabaseHas('products', [
                'store_id' => $store->id,
                'sku' => $sku,
                'adult_only' => true,
                'pickup_only' => true,
            ]);
            Log::shouldHaveReceived('warning')
                ->withArgs(fn ($message, $context = []) => str_contains($message, 'is_alcohol') && ($context['is_alcohol'] ?? null) === $value)
                ->once();
        }
    }

    public function test_explicit_null_is_alcohol_clears_flags(): void
    {
        $store = Store::factory()->create(['code' => 'NDS_STORE']);

        $this->createThenUpdateAlcohol('WINE-NULL', true, null);

        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'sku' => 'WINE-NULL',
            'adult_only' => false,
            'pickup_only' => false,
        ]);
    }

    public function test_recognized_is_alcohol_values_keep_previous_semantics(): void
    {
        $store = Store::factory()->create(['code' => 'NDS_STORE']);
        $cases = [[true, true], ['true', true], [1, true], [false, false], ['false', false], [0, false]];

        foreach ($cases as $i => [$value, $expected]) {
            $sku = 'ALC-CASE-'.$i;
            $this->createThenUpdateAlcohol($sku, ! $expected, $value);

            $this->assertDatabaseHas('products', [
                'store_id' => $store->id,
                'sku' => $sku,
                'adult_only' => $expected,
                'pickup_only' => $expected,
            ]);
        }
    }
}
