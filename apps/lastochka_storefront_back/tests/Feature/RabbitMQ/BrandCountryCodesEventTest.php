<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Brand;
use App\RabbitMQ\Transformers\BrandEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrandCountryCodesEventTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumer = new EventConsumer('brands', config('rabbitmq-events.entities.brands'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildBrandEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'brand_' . Str::random(5),
            'source' => 'admin',
            'title' => 'IEK',
            'position' => 0,
            'is_active' => true,
        ], $overrides);
    }

    private function createMetadata(Brand $brand, string $externalId): void
    {
        EventSyncMetadata::create([
            'entity_type' => 'brands',
            'entity_id' => $brand->id,
            'external_id' => $externalId,
            'source' => 'admin',
            'shop_code' => 'default',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);
    }

    public function test_brand_created_stores_country_codes(): void
    {
        $this->consumer->processEvent($this->buildBrandEvent([
            'id' => 'brand_codes',
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
            'country_production' => 'Китай',
            'country_production_code' => 'CN',
        ]));

        // external_id живёт в event_sync_metadata, на модели его нет.
        $metadata = EventSyncMetadata::where('entity_type', 'brands')
            ->where('external_id', 'brand_codes')
            ->firstOrFail();
        $brand = Brand::findOrFail($metadata->entity_id);

        $this->assertSame('RU', $brand->country_origin_code);
        $this->assertSame('CN', $brand->country_production_code);
        $this->assertSame('Россия', $brand->country_origin);
        $this->assertSame('Китай', $brand->country_production);
    }

    public function test_brand_updated_with_null_code_clears_it(): void
    {
        $brand = Brand::factory()->create([
            'source' => 'admin',
            'external_id' => 'brand_clear',
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
        ]);
        $this->createMetadata($brand, 'brand_clear');

        $this->consumer->processEvent($this->buildBrandEvent([
            'event_type' => 'updated',
            'id' => 'brand_clear',
            'country_origin' => null,
            'country_origin_code' => null,
        ]));

        $brand->refresh();

        $this->assertNull($brand->country_origin_code);
        $this->assertNull($brand->country_origin);
    }

    public function test_partial_update_without_code_keys_keeps_codes(): void
    {
        $brand = Brand::factory()->create([
            'source' => 'admin',
            'external_id' => 'brand_keep',
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
            'country_production' => 'Китай',
            'country_production_code' => 'CN',
        ]);
        $this->createMetadata($brand, 'brand_keep');

        $this->consumer->processEvent($this->buildBrandEvent([
            'event_type' => 'updated',
            'id' => 'brand_keep',
            'title' => 'IEK Group',
        ]));

        $brand->refresh();

        $this->assertSame('IEK Group', $brand->title);
        $this->assertSame('RU', $brand->country_origin_code);
        $this->assertSame('CN', $brand->country_production_code);
    }

    public function test_from_event_conditional_set_for_country_codes(): void
    {
        $transformer = new BrandEventTransformer();

        $without = $transformer->fromEvent([
            'source' => 'admin',
            'title' => 'IEK',
        ]);

        foreach (['country_origin_code', 'country_production_code'] as $field) {
            $this->assertArrayNotHasKey($field, $without);
        }

        $with = $transformer->fromEvent([
            'source' => 'admin',
            'title' => 'IEK',
            'country_origin_code' => 'RU',
            'country_production_code' => null,
        ]);

        $this->assertSame('RU', $with['country_origin_code']);
        $this->assertNull($with['country_production_code']);
    }
}
