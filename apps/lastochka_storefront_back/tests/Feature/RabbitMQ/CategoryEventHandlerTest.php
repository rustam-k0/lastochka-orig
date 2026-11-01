<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Category;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventPendingReference;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CategoryEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('categories', config('rabbitmq-events.entities.categories'));

        Storage::fake('public');
    }

    public function test_category_created_event_creates_category(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_001',
            'title' => 'Молочные продукты',
            'active' => true,
            'meta_title' => 'Молочные продукты - купить',
            'meta_description' => 'Свежие молочные продукты с доставкой',
            'meta_keywords' => 'молоко, сыр, творог',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('categories', [
            'name' => 'Молочные продукты',
            'is_active' => true,
            'source' => 'bagisto',
            'meta_title' => 'Молочные продукты - купить',
            'meta_description' => 'Свежие молочные продукты с доставкой',
            'meta_keywords' => 'молоко, сыр, творог',
        ]);

        // Категория привязана к магазину через pivot
        $category = Category::where('name', 'Молочные продукты')->first();
        $this->assertTrue($category->stores->contains($store));
    }

    public function test_category_updated_event_updates_category(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Старое название',
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_123',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'cat_123',
            'shop_code' => 'MSK_002',
            'title' => 'Новое название',
            'active' => false,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $category->refresh();
        $this->assertEquals('Новое название', $category->name);
        $this->assertFalse($category->is_active);
    }

    public function test_category_deleted_event_soft_deletes_category(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Категория для удаления',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_del_123',
            'source' => 'bagisto',
            'shop_code' => 'MSK_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'cat_del_123',
            'shop_code' => 'MSK_003',
            'title' => 'Категория для удаления',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_category_with_parent_creates_pending_reference_when_parent_not_exists(): void
    {
        Store::factory()->create(['code' => 'MSK_004']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'cat_child',
            'shop_code' => 'MSK_004',
            'parent_id' => 'cat_parent',
            'parent_source' => 'bagisto',
            'title' => 'Сыры',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('categories', [
            'name' => 'Сыры',
            'parent_id' => null,
        ]);

        // global_scope=true → shop_code=null для глобальных справочников
        $this->assertDatabaseHas('event_pending_references', [
            'entity_type' => 'categories',
            'field_name' => 'parent_id',
            'reference_type' => 'categories',
            'reference_external_id' => 'cat_parent',
            'reference_source' => 'bagisto',
            'shop_code' => null,
        ]);
    }

    public function test_category_with_parent_links_directly_when_parent_exists(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_005']);

        $parentCategory = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $parentCategory->id,
            'external_id' => 'cat_parent',
            'source' => 'bagisto',
            'shop_code' => 'MSK_005',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'cat_child',
            'shop_code' => 'MSK_005',
            'parent_id' => 'cat_parent',
            'parent_source' => 'bagisto',
            'title' => 'Сыры',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $childCategory = Category::where('name', 'Сыры')->first();
        $this->assertNotNull($childCategory);
        $this->assertEquals($parentCategory->id, $childCategory->parent_id);

        $this->assertDatabaseMissing('event_pending_references', [
            'entity_type' => 'categories',
            'reference_external_id' => 'cat_parent',
        ]);
    }

    public function test_pending_reference_resolved_when_parent_created(): void
    {
        Store::factory()->create(['code' => 'MSK_006']);

        $childEventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'cat_child',
            'shop_code' => 'MSK_006',
            'parent_id' => 'cat_parent',
            'parent_source' => 'bagisto',
            'title' => 'Сыры',
        ]);

        $this->consumer->processEvent($childEventData);

        $childCategory = Category::where('name', 'Сыры')->first();
        $this->assertNull($childCategory->parent_id);

        $parentEventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'cat_parent',
            'shop_code' => 'MSK_006',
            'title' => 'Молочные продукты',
        ]);

        $this->consumer->processEvent($parentEventData);

        $parentCategory = Category::where('name', 'Молочные продукты')->first();
        $this->assertNotNull($parentCategory);

        $pendingRef = EventPendingReference::where('reference_external_id', 'cat_parent')->first();
        $this->assertNotNull($pendingRef);

        $resolved = $pendingRef->attemptResolve();
        $this->assertTrue($resolved);

        $childCategory->refresh();
        $this->assertEquals($parentCategory->id, $childCategory->parent_id);

        $pendingRef->refresh();
        $this->assertNotNull($pendingRef->resolved_at);
    }

    public function test_image_downloaded_on_category_create(): void
    {
        Store::factory()->create(['code' => 'MSK_007']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                file_get_contents(__DIR__.'/../../fixtures/test_image.png') ?: 'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_007',
            'title' => 'Категория с изображением',
            'image_url' => 'https://cdn.example.com/categories/milk.jpg',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $category = Category::where('name', 'Категория с изображением')->first();
        $this->assertNotNull($category);
        $this->assertNotNull($category->image_path);
        $this->assertStringStartsWith('categories/', $category->image_path);
    }

    public function test_background_image_downloaded_on_category_create(): void
    {
        Store::factory()->create(['code' => 'MSK_BG1']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-bg-content', 200, ['Content-Type' => 'image/png']),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_BG1',
            'title' => 'Категория с фоном',
            'background_image_url' => 'https://cdn.example.com/categories/bg.png',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $category = Category::where('name', 'Категория с фоном')->first();
        $this->assertNotNull($category);
        $this->assertNotNull($category->background_image_path);
        $this->assertStringStartsWith('categories/backgrounds/', $category->background_image_path);
    }

    public function test_background_image_cleared_when_event_sends_null(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_BG2']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Фон будет снят',
            'background_image_path' => 'categories/backgrounds/old.png',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_bg2',
            'source' => 'bagisto',
            'shop_code' => 'MSK_BG2',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'cat_bg2',
            'shop_code' => 'MSK_BG2',
            'title' => 'Фон будет снят',
            'background_image_url' => null,
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $this->assertNull($category->fresh()->background_image_path);
    }

    /**
     * ALD-133: категория «Соки / Морс» (admin id 20) уехала на витрину
     * с валидным image_url 2026-07-21, но фото так и не появилось — path
     * остался NULL навсегда, потому что старая реализация безусловно
     * обнуляла image_path в fromEvent() на КАЖДОЕ событие, а неудачное
     * скачивание в processImage() просто логировало warning, не
     * восстанавливая прежнее значение. Фикс: fromEvent() больше не трогает
     * image_path, а processImage() при сбое скачивания оставляет
     * сохранённый путь как есть.
     */
    public function test_category_image_download_failure_keeps_previous_image_path(): void
    {
        $store = Store::factory()->create(['code' => 'IMG_FAIL_01']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Соки / Морс (тест)',
            'image_path' => 'categories/existing-good.png',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_img_fail',
            'source' => 'bagisto',
            'shop_code' => 'IMG_FAIL_01',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        Http::fake([
            'https://cdn.example.com/*' => Http::response('', 500),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'cat_img_fail',
            'shop_code' => 'IMG_FAIL_01',
            'title' => 'Соки / Морс (тест)',
            'image_url' => 'https://cdn.example.com/categories/broken.jpg',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertSame('categories/existing-good.png', $category->fresh()->image_path);
    }

    /**
     * ALD-133: downloadImage() теперь делает до 3 попыток — большинство
     * сбоев скачивания транзиентны (один неудачный ответ подряд с успешным
     * следующим).
     */
    public function test_category_image_download_retries_transient_failure_then_succeeds(): void
    {
        $store = Store::factory()->create(['code' => 'IMG_RETRY_01']);

        Http::fake([
            'https://cdn.example.com/*' => Http::sequence()
                ->push('', 500)
                ->push(
                    file_get_contents(__DIR__.'/../../fixtures/test_image.png') ?: 'fake-image-content',
                    200,
                    ['Content-Type' => 'image/png']
                ),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'IMG_RETRY_01',
            'title' => 'Категория с ретраем',
            'image_url' => 'https://cdn.example.com/categories/retry.jpg',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $category = Category::where('name', 'Категория с ретраем')->first();
        $this->assertNotNull($category);
        $this->assertNotNull($category->image_path);
        $this->assertStringStartsWith('categories/', $category->image_path);
    }

    /**
     * ALD-133 fix round 1: 4xx — постоянная ошибка (битая/удалённая ссылка,
     * доступ запрещён), retry на ней заведомо бесполезен и не должен
     * тратить бюджет времени внутри транзакции консьюмера. Проверяем через
     * счётчик реально отправленных запросов (Http::fake + assertSentCount),
     * а не только итоговый результат — иначе тест не отличит "не
     * повторяли" от "повторили 3 раза и всё равно не скачали".
     */
    public function test_category_image_download_does_not_retry_on_client_error(): void
    {
        Store::factory()->create(['code' => 'IMG_4XX_01']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response('', 404),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'IMG_4XX_01',
            'title' => 'Категория с битой ссылкой',
            'image_url' => 'https://cdn.example.com/categories/missing.jpg',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $category = Category::where('name', 'Категория с битой ссылкой')->first();
        $this->assertNotNull($category);
        $this->assertNull($category->image_path);

        // Ровно 1 запрос — ни одного повтора на 404.
        Http::assertSentCount(1);
    }

    public function test_category_image_cleared_when_event_sends_null_image_url(): void
    {
        $store = Store::factory()->create(['code' => 'IMG_CLEAR_01']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Фото будет снято',
            'image_path' => 'categories/old.png',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_img_clear',
            'source' => 'bagisto',
            'shop_code' => 'IMG_CLEAR_01',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'cat_img_clear',
            'shop_code' => 'IMG_CLEAR_01',
            'title' => 'Фото будет снято',
            'image_url' => null,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertNull($category->fresh()->image_path);
    }

    public function test_category_updated_without_image_url_key_keeps_existing_image(): void
    {
        $store = Store::factory()->create(['code' => 'IMG_KEEP_01']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Без ключа картинки',
            'image_path' => 'categories/keep.png',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_img_keep',
            'source' => 'bagisto',
            'shop_code' => 'IMG_KEEP_01',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Событие без ключа image_url вовсе (источник, который это поле
        // не публикует) не должно трогать сохранённый путь.
        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'cat_img_keep',
            'shop_code' => 'IMG_KEEP_01',
            'title' => 'Без ключа картинки, обновлено',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertSame('categories/keep.png', $category->fresh()->image_path);
    }

    public function test_category_saved_without_attach_when_store_missing(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'NON_EXISTENT_SHOP',
            'title' => 'Категория без магазина',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('categories', [
            'name' => 'Категория без магазина',
        ]);

        $this->assertDatabaseMissing('stores', [
            'code' => 'NON_EXISTENT_SHOP',
        ]);

        $category = Category::where('name', 'Категория без магазина')->first();
        $this->assertCount(0, $category->stores);
    }

    public function test_existing_category_is_linked_by_name(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_008']);

        $existingCategory = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'ext_cat_8',
            'shop_code' => 'MSK_008',
            'title' => 'Молочные продукты',
            'active' => false,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseCount('categories', 1);

        $this->assertDatabaseHas('event_sync_metadata', [
            'entity_type' => 'categories',
            'entity_id' => $existingCategory->id,
            'external_id' => 'ext_cat_8',
            'source' => 'bagisto',
        ]);

        $existingCategory->refresh();
        $this->assertFalse($existingCategory->is_active);
    }

    public function test_duplicate_event_is_skipped(): void
    {
        Store::factory()->create(['code' => 'MSK_009']);
        $eventId = Str::uuid()->toString();

        $eventData = $this->buildEventData([
            'event_id' => $eventId,
            'event_type' => 'created',
            'shop_code' => 'MSK_009',
            'title' => 'Уникальная категория',
        ]);

        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        $this->assertDatabaseCount('categories', 1);
    }

    public function test_outdated_event_is_ignored(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_010']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Актуальное название',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_10',
            'source' => 'bagisto',
            'shop_code' => 'MSK_010',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
            // Guard устаревших событий сравнивает per-type метку из
            // metadata.event_timestamps[тип], а не last_event_timestamp:
            // при пустом event_timestamps он намеренно пропускает событие
            // (обратная совместимость для записей, созданных до этой правки).
            'metadata' => ['event_timestamps' => ['updated' => now()->toIso8601String()]],
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'event_timestamp' => now()->subDay()->toIso8601String(),
            'id' => 'cat_10',
            'shop_code' => 'MSK_010',
            'title' => 'Устаревшее название',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $category->refresh();
        $this->assertEquals('Актуальное название', $category->name);
    }

    public function test_validation_fails_without_required_fields(): void
    {
        $this->expectException(\Comrades\RabbitMQEvents\Exceptions\InvalidEventException::class);

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'cat_invalid',
            'source' => 'bagisto',
            'shop_code' => 'MSK_011',
        ];

        $this->consumer->processEvent($eventData);
    }

    public function test_same_category_from_two_shops_creates_one_category_with_two_pivots(): void
    {
        $store1 = Store::factory()->create(['code' => 'SHOP_A']);
        $store2 = Store::factory()->create(['code' => 'SHOP_B']);

        $result1 = $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-multi-1',
            'id' => 'CAT_SHARED_1',
            'shop_code' => 'SHOP_A',
            'title' => 'Мясо/Птица',
        ]));

        $result2 = $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-multi-2',
            'id' => 'CAT_SHARED_1',
            'shop_code' => 'SHOP_B',
            'title' => 'Мясо/Птица',
        ]));

        $this->assertEquals('ack', $result1);
        $this->assertEquals('ack', $result2);

        // Одна категория, не дубликаты
        $categories = Category::where('name', 'Мясо/Птица')->get();
        $this->assertCount(1, $categories);

        // Привязана к обоим магазинам через pivot
        $category = $categories->first();
        $this->assertCount(2, $category->stores);
        $this->assertTrue($category->stores->contains($store1));
        $this->assertTrue($category->stores->contains($store2));
    }

    public function test_three_shops_create_one_category_with_three_pivots(): void
    {
        Store::factory()->create(['code' => 'SHOP_X']);
        Store::factory()->create(['code' => 'SHOP_Y']);
        Store::factory()->create(['code' => 'SHOP_Z']);

        $shops = ['SHOP_X', 'SHOP_Y', 'SHOP_Z'];

        foreach ($shops as $i => $shopCode) {
            $result = $this->consumer->processEvent($this->buildEventData([
                'event_id' => "evt-3shop-{$i}",
                'id' => 'CAT_3SHOP',
                'shop_code' => $shopCode,
                'title' => 'Овощи/фрукты',
            ]));

            $this->assertEquals('ack', $result);
        }

        // Одна категория
        $categories = Category::where('name', 'Овощи/фрукты')->get();
        $this->assertCount(1, $categories);

        // Привязана к трём магазинам
        $this->assertCount(3, $categories->first()->stores);
    }

    public function test_update_with_same_shop_code_does_not_create_duplicate(): void
    {
        Store::factory()->create(['code' => 'SHOP_UPD']);

        $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-upd-1',
            'event_timestamp' => '2026-03-05T10:00:00+00:00',
            'id' => 'CAT_UPD_1',
            'shop_code' => 'SHOP_UPD',
            'title' => 'Оригинал',
        ]));

        $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-upd-2',
            'event_timestamp' => '2026-03-05T11:00:00+00:00',
            'id' => 'CAT_UPD_1',
            'shop_code' => 'SHOP_UPD',
            'title' => 'Обновлённое',
        ]));

        // Только 1 категория
        $categories = Category::all();
        $this->assertCount(1, $categories);
        $this->assertEquals('Обновлённое', $categories->first()->name);

        // Только 1 metadata
        $metadatas = EventSyncMetadata::where('entity_type', 'categories')
            ->where('external_id', 'CAT_UPD_1')
            ->get();
        $this->assertCount(1, $metadatas);
    }

    public function test_two_admin_categories_with_same_title_create_two_local_categories(): void
    {
        Store::factory()->create(['code' => 'SHOP_DUP']);

        $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-dup-root',
            'id' => 'ADMIN_ROOT',
            'shop_code' => 'SHOP_DUP',
            'title' => 'Мясо/Птица/Деликатесы',
        ]));

        $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-dup-sub',
            'id' => 'ADMIN_DUP_UNDER_ALL',
            'shop_code' => 'SHOP_DUP',
            'title' => 'Мясо/Птица/Деликатесы',
        ]));

        $categories = Category::where('name', 'Мясо/Птица/Деликатесы')->get();
        $this->assertCount(2, $categories);

        $this->assertDatabaseHas('event_sync_metadata', [
            'entity_type' => 'categories',
            'external_id' => 'ADMIN_ROOT',
            'source' => 'bagisto',
        ]);
        $this->assertDatabaseHas('event_sync_metadata', [
            'entity_type' => 'categories',
            'external_id' => 'ADMIN_DUP_UNDER_ALL',
            'source' => 'bagisto',
        ]);
    }

    public function test_delete_of_duplicate_admin_category_does_not_affect_root(): void
    {
        Store::factory()->create(['code' => 'SHOP_DUP_DEL']);

        $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-del-root',
            'id' => 'ROOT_67',
            'shop_code' => 'SHOP_DUP_DEL',
            'title' => 'Хлеб/выпечка',
        ]));
        $rootId = Category::where('name', 'Хлеб/выпечка')->value('id');
        $this->assertNotNull($rootId);

        $this->consumer->processEvent($this->buildEventData([
            'event_id' => 'evt-del-sub-create',
            'id' => 'SUB_110',
            'shop_code' => 'SHOP_DUP_DEL',
            'title' => 'Хлеб/выпечка',
        ]));
        $dupId = Category::where('name', 'Хлеб/выпечка')
            ->where('id', '!=', $rootId)
            ->value('id');
        $this->assertNotNull($dupId);

        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'deleted',
            'event_id' => 'evt-del-sub-delete',
            'id' => 'SUB_110',
            'shop_code' => 'SHOP_DUP_DEL',
            'title' => 'Хлеб/выпечка',
        ]));

        $this->assertSoftDeleted('categories', ['id' => $dupId]);
        $this->assertDatabaseHas('categories', ['id' => $rootId, 'deleted_at' => null]);
    }

    public function test_category_event_applies_tag_fields(): void
    {
        Store::factory()->create(['code' => 'TAG_01']);

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'TAG_01',
            'title' => 'Выпечка',
            'tag_text' => 'Готовим свежие каждый день',
            'tag_color' => '#FF5733',
        ]));

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('categories', [
            'name' => 'Выпечка',
            'tag_text' => 'Готовим свежие каждый день',
            'tag_color' => '#FF5733',
        ]);
    }

    public function test_category_updated_without_tag_keys_keeps_existing_tag(): void
    {
        $store = Store::factory()->create(['code' => 'TAG_02']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Выпечка',
            'tag_text' => 'Свежее',
            'tag_color' => '#00AA00',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_tag_2',
            'source' => 'bagisto',
            'shop_code' => 'TAG_02',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'cat_tag_2',
            'shop_code' => 'TAG_02',
            'title' => 'Выпечка обновлённая',
            // tag_text / tag_color намеренно отсутствуют — партиальный payload
        ]));

        $this->assertEquals('ack', $result);
        $category->refresh();
        $this->assertSame('Свежее', $category->tag_text);
        $this->assertSame('#00AA00', $category->tag_color);
    }

    public function test_category_updated_with_null_tag_clears_tag(): void
    {
        $store = Store::factory()->create(['code' => 'TAG_03']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Выпечка',
            'tag_text' => 'Старый тег',
            'tag_color' => '#00AA00',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_tag_3',
            'source' => 'bagisto',
            'shop_code' => 'TAG_03',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'cat_tag_3',
            'shop_code' => 'TAG_03',
            'title' => 'Выпечка',
            'tag_text' => null,
            'tag_color' => null,
        ]));

        $this->assertEquals('ack', $result);
        $category->refresh();
        $this->assertNull($category->tag_text);
        $this->assertNull($category->tag_color);
    }

    /**
     * Построить данные события с дефолтными значениями.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'cat_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'TEST_'.Str::random(3),
            'title' => 'Тестовая категория',
            'active' => true,
        ], $overrides);
    }
}
