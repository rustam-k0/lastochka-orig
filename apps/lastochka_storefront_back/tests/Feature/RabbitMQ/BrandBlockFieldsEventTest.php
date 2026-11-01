<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Brand;
use App\RabbitMQ\Transformers\BrandEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BrandBlockFieldsEventTest extends TestCase
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

    public function test_brand_created_stores_block_fields_and_downloads_certificate(): void
    {
        Storage::fake('public');
        Http::fake([
            'admin.example/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->consumer->processEvent($this->buildBrandEvent([
            'id' => 'brand_new',
            'link' => 'https://iek.ru',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_url' => 'https://admin.example/files/brands/cert.pdf',
            'certificate_name' => 'Сертификат.pdf',
            'logo_image_url' => null,
        ]));

        $metadata = EventSyncMetadata::where('entity_type', 'brands')
            ->where('external_id', 'brand_new')
            ->firstOrFail();
        $brand = Brand::findOrFail($metadata->entity_id);
        $this->assertSame('https://iek.ru', $brand->link);
        $this->assertSame('Россия', $brand->country_origin);
        $this->assertSame('Китай', $brand->country_production);
        $this->assertSame('Сертификат.pdf', $brand->certificate_name);
        $this->assertNotNull($brand->certificate_path);
        $this->assertStringStartsWith('brands/certificates/', $brand->certificate_path);
        $this->assertStringEndsWith('.pdf', $brand->certificate_path);
        Storage::disk('public')->assertExists($brand->certificate_path);
    }

    public function test_brand_updated_with_null_certificate_clears_path(): void
    {
        $brand = Brand::factory()->create([
            'source' => 'admin',
            'external_id' => 'brand_clear',
            'certificate_path' => 'brands/certificates/old.pdf',
            'certificate_name' => 'old.pdf',
            'link' => 'https://keep.example',
        ]);
        $this->createMetadata($brand, 'brand_clear');

        $this->consumer->processEvent($this->buildBrandEvent([
            'event_type' => 'updated',
            'id' => 'brand_clear',
            'title' => $brand->title,
            'certificate_url' => null,
            'certificate_name' => null,
            'link' => 'https://keep.example',
        ]));

        $brand->refresh();
        $this->assertNull($brand->certificate_path);
        $this->assertNull($brand->certificate_name);
        $this->assertSame('https://keep.example', $brand->link);
    }

    public function test_brand_partial_update_without_keys_keeps_block_fields(): void
    {
        $brand = Brand::factory()->create([
            'source' => 'admin',
            'external_id' => 'brand_keep',
            'link' => 'https://keep.example',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_path' => 'brands/certificates/keep.pdf',
            'certificate_name' => 'keep.pdf',
        ]);
        $this->createMetadata($brand, 'brand_keep');

        // Событие без ключей блока бренда (например, от смены позиции).
        $this->consumer->processEvent($this->buildBrandEvent([
            'event_type' => 'updated',
            'id' => 'brand_keep',
            'title' => $brand->title,
            'position' => 5,
        ]));

        $brand->refresh();
        $this->assertSame('https://keep.example', $brand->link);
        $this->assertSame('Россия', $brand->country_origin);
        $this->assertSame('Китай', $brand->country_production);
        $this->assertSame('brands/certificates/keep.pdf', $brand->certificate_path);
        $this->assertSame('keep.pdf', $brand->certificate_name);
        $this->assertSame(5, $brand->position);
    }

    public function test_from_event_conditional_set_for_block_fields(): void
    {
        $transformer = app(BrandEventTransformer::class);
        $base = ['event_id' => 'e', 'id' => '1', 'source' => 'admin', 'title' => 'Бренд'];

        $without = $transformer->fromEvent($base);
        foreach (['link', 'country_origin', 'country_production', 'certificate_name'] as $field) {
            $this->assertArrayNotHasKey($field, $without, "{$field}: без ключа атрибут не должен появляться");
        }

        $with = $transformer->fromEvent($base + [
            'link' => 'https://iek.ru',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_name' => 'Сертификат.pdf',
        ]);
        $this->assertSame('https://iek.ru', $with['link']);
        $this->assertSame('Россия', $with['country_origin']);
        $this->assertSame('Китай', $with['country_production']);
        $this->assertSame('Сертификат.pdf', $with['certificate_name']);
    }
}
