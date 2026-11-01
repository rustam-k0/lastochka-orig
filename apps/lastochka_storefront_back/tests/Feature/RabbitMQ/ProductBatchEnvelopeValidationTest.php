<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\StorefrontSetting;
use App\RabbitMQ\Transformers\ProductBatchEventTransformer;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Валидация конверта батч-событий товаров (validate-only трансформер).
 *
 * Контракт сетевого каталога (раздел 3): конверт `product.batch_updated`
 * в сетевом режиме приходит БЕЗ shop_code — карточка глобальна. Для
 * price/stock-батчей shop_code обязателен всегда (раздел 4-5).
 * При выключенном флаге валидация — байт-в-байт легаси.
 */
class ProductBatchEnvelopeValidationTest extends TestCase
{
    use RefreshDatabase;

    private ProductBatchEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->transformer = new ProductBatchEventTransformer();
    }

    private function setNetworkCatalog(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function envelope(string $eventType, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => $eventType,
            'event_source' => '1c',
            'source' => '1c',
            'items' => [
                ['id' => (string) Str::uuid(), 'sku' => 'SKU-1', 'title' => 'Товар', 'unit_type' => 'piece'],
            ],
        ], $overrides);
    }

    public function test_batch_updated_envelope_without_shop_code_is_valid_in_network_mode(): void
    {
        $this->setNetworkCatalog(true);

        $this->assertTrue($this->transformer->validate($this->envelope('batch_updated')));
    }

    public function test_batch_updated_envelope_with_shop_code_stays_valid_in_network_mode(): void
    {
        $this->setNetworkCatalog(true);

        $this->assertTrue($this->transformer->validate(
            $this->envelope('batch_updated', ['shop_code' => 'shop-legacy'])
        ));
    }

    public function test_batch_updated_envelope_without_shop_code_is_rejected_when_flag_off(): void
    {
        $this->setNetworkCatalog(false);

        $this->assertFalse($this->transformer->validate($this->envelope('batch_updated')));
    }

    public function test_price_and_stock_batch_envelopes_require_shop_code_even_in_network_mode(): void
    {
        $this->setNetworkCatalog(true);

        $this->assertFalse($this->transformer->validate($this->envelope('price_batch_updated')));
        $this->assertFalse($this->transformer->validate($this->envelope('stock_batch_updated')));
    }
}
