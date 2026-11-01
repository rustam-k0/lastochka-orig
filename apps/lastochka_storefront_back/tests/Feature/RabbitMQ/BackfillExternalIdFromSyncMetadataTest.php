<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Brand;
use App\Models\Promotion;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Трансформеры не писали external_id, поэтому у существующих записей он NULL.
 * Миграция восстанавливает его из event_sync_metadata (связка entity_type + entity_id).
 */
final class BackfillExternalIdFromSyncMetadataTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_13_120000_backfill_external_id_from_event_sync_metadata.php';

    public function test_fills_external_id_from_latest_metadata_of_same_source(): void
    {
        $store = Store::factory()->create();
        $promotion = $this->promotion($store, 'app', null);

        $this->metadata('merchant_sales', $promotion->id, 'old_7', 'app');
        $this->metadata('merchant_sales', $promotion->id, '7', 'app');

        $this->runMigration();

        $this->assertSame('7', $promotion->fresh()->external_id);
    }

    public function test_keeps_rows_without_metadata_and_already_filled_rows(): void
    {
        $store = Store::factory()->create();
        $withoutMetadata = $this->promotion($store, 'app', null);
        $alreadyFilled = $this->promotion($store, 'app', 'kept');
        $this->metadata('merchant_sales', $alreadyFilled->id, 'other', 'app');

        $this->runMigration();

        $this->assertNull($withoutMetadata->fresh()->external_id);
        $this->assertSame('kept', $alreadyFilled->fresh()->external_id);
    }

    public function test_ignores_metadata_of_other_source(): void
    {
        $store = Store::factory()->create();
        $promotion = $this->promotion($store, 'app', null);
        $this->metadata('merchant_sales', $promotion->id, 'foreign', 'bagisto');

        $this->runMigration();

        $this->assertNull($promotion->fresh()->external_id);
    }

    public function test_does_not_mix_entity_types_with_same_entity_id(): void
    {
        $store = Store::factory()->create();
        $promotion = $this->promotion($store, 'app', null);
        $brand = Brand::factory()->create(['source' => 'app', 'external_id' => null]);
        DB::table('brands')->where('id', $brand->id)->update(['id' => $promotion->id]);

        $this->metadata('merchant_sales', $promotion->id, 'sale_1', 'app');
        $this->metadata('brands', $promotion->id, 'brand_1', 'app');

        $this->runMigration();

        $this->assertSame('sale_1', $promotion->fresh()->external_id);
        $this->assertSame('brand_1', DB::table('brands')->where('id', $promotion->id)->value('external_id'));
    }

    private function runMigration(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    private function promotion(Store $store, string $source, ?string $externalId): Promotion
    {
        $promotion = Promotion::factory()->create(['store_id' => $store->id, 'source' => $source]);
        // Фабрика и fromEvent теперь пишут external_id — возвращаем «исторический» NULL
        DB::table('promotions')->where('id', $promotion->id)->update(['external_id' => $externalId]);

        return $promotion;
    }

    private function metadata(string $entityType, int $entityId, string $externalId, string $source): void
    {
        EventSyncMetadata::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'external_id' => $externalId,
            'source' => $source,
            'shop_code' => Str::random(8),
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
        ]);
    }
}
