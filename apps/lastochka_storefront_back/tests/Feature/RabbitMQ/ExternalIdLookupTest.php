<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Announcement;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\ProductGroup;
use App\Models\Promocode;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\Story;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Если метаданных сущности нет или entity_id в них потерян, пакет ищет сущность через
 * findExistingEntity() по external_id и source. Трансформеры не писали external_id
 * в модель (на стендах он NULL у всех записей), поэтому удаление при осиротевших
 * метаданных подтверждалось, а сущность оставалась на витрине.
 */
final class ExternalIdLookupTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_CODE = 'EXT_001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Store::factory()->create(['code' => self::STORE_CODE]);
    }

    /**
     * @return array<string, array{string, class-string<Model>}>
     */
    public static function entities(): array
    {
        return [
            'акция' => ['merchant_sales', Promotion::class],
            'товарная группа' => ['merchant_product_groups', ProductGroup::class],
            'промокод' => ['merchant_promocodes', Promocode::class],
            'баннер' => ['merchant_banners', Banner::class],
            'история' => ['stories', Story::class],
            'бренд' => ['brands', Brand::class],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('entities')]
    public function test_created_event_stores_external_id(string $entityType, string $modelClass): void
    {
        $externalId = 'ext_'.Str::random(6);

        $model = $this->createViaEvent($entityType, $modelClass, $externalId);

        $this->assertSame($externalId, $model->external_id);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('entities')]
    public function test_deleted_event_with_orphan_metadata_removes_entity(string $entityType, string $modelClass): void
    {
        $externalId = 'ext_'.Str::random(6);
        $model = $this->createViaEvent($entityType, $modelClass, $externalId);

        EventSyncMetadata::where('entity_type', $entityType)
            ->where('external_id', $externalId)
            ->update(['entity_id' => null]);

        $result = $this->consumer($entityType)->processEvent(
            $this->payload($entityType, $externalId, 'deleted', now()->addMinute()->toIso8601String())
        );

        $this->assertSame('ack', $result);

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $this->assertSoftDeleted($model->getTable(), ['id' => $model->getKey()]);
        } else {
            $this->assertDatabaseMissing($model->getTable(), ['id' => $model->getKey()]);
        }
    }

    public function test_announcement_deleted_event_with_orphan_metadata_removes_announcement(): void
    {
        // Объявления пишут external_id, но findExistingEntity брал source и external_id
        // из $transformedData, а на этом пути пакет передаёт пустой массив
        $announcement = Announcement::factory()->create([
            'source' => 'bagisto',
            'external_id' => 'ann_orphan',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'announcements',
            'entity_id' => null,
            'external_id' => 'ann_orphan',
            'source' => 'bagisto',
            'shop_code' => config('rabbitmq-events.shop_code'),
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $result = $this->consumer('announcements')->processEvent([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'deleted',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ann_orphan',
            'source' => 'bagisto',
            'title' => $announcement->title,
            'short_description' => 'Кратко',
            'description' => 'Описание',
        ]);

        $this->assertSame('ack', $result);
        $this->assertSoftDeleted('announcements', ['id' => $announcement->id]);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function createViaEvent(string $entityType, string $modelClass, string $externalId): Model
    {
        $result = $this->consumer($entityType)->processEvent(
            $this->payload($entityType, $externalId, 'created', now()->toIso8601String())
        );

        $this->assertSame('ack', $result);

        $entityId = EventSyncMetadata::where('entity_type', $entityType)
            ->where('external_id', $externalId)
            ->value('entity_id');

        $this->assertNotNull($entityId, "Событие {$entityType}.created не создало сущность");

        return $modelClass::query()->findOrFail($entityId);
    }

    private function consumer(string $entityType): EventConsumer
    {
        return new EventConsumer($entityType, config("rabbitmq-events.entities.{$entityType}"));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $entityType, string $externalId, string $eventType, string $timestamp): array
    {
        $base = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => $eventType,
            'event_timestamp' => $timestamp,
            'id' => $externalId,
            'source' => 'bagisto',
            'is_active' => true,
        ];

        $shop = ['shop_id' => self::STORE_CODE, 'shop_source' => 'bagisto'];

        return $base + match ($entityType) {
            'merchant_sales' => $shop + [
                'title' => 'Акция',
                'discount_percent' => 10,
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addWeek()->toIso8601String(),
            ],
            'merchant_product_groups' => $shop + ['title' => 'Группа'],
            'merchant_promocodes' => $shop + [
                'title' => 'Промокод',
                'code' => 'CODE_'.strtoupper($externalId),
                'discount_percent' => 10,
                'valid_from' => now()->toIso8601String(),
                'valid_to' => now()->addMonth()->toIso8601String(),
            ],
            'merchant_banners' => $shop + ['title' => 'Баннер', 'link_type' => 'none'],
            'stories' => [
                'title' => 'История',
                'expires_at' => now()->addMonth()->toIso8601String(),
                'link_type' => 'none',
            ],
            'brands' => ['title' => 'Бренд', 'position' => 0],
        };
    }
}
