<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Shop;
use App\Services\ShopService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Скоуп публикации category.* по магазинам — Category::publishingOnlyForShops().
 *
 * Контекст (инцидент 2026-08-10): publishMultiEntity пишет в outbox по событию
 * на КАЖДУЮ связь shopCategories. Пока категория меняется сама (title, картинка)
 * это верно — знать должны все магазины. Но привязка категории к ОДНОМУ магазину
 * тоже рассылалась всем, поэтому заведение магазинов по одному давало
 * 1+2+...+N событий. На проде 654 магазина за час дали ~29k событий на две
 * категории, забили диск и уронили RabbitMQ по disk alarm.
 *
 * Здесь закреплено обе стороны контракта: скоуп сужает рассылку до нужных
 * магазинов и при этом НЕ ломает штатный фан-аут для изменений самой категории.
 */
class CategoryPublishShopScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // Сброс статического флага, чтобы Observer-ы rabbitmq-events
        // перерегистрировались с новым event dispatcher после refreshApplication()
        // (иначе outbox-события не пишутся). Зеркалит CategoryPublishMultiEntityTest.
        $ref = new \ReflectionClass(RabbitMQEventsServiceProvider::class);
        $prop = $ref->getProperty('observersRegistered');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();
    }

    private function makeShop(string $suffix): Shop
    {
        return Shop::query()->create([
            'title' => 'scope-shop-' . $suffix,
            'code' => 'scope-' . $suffix . '-' . uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    /**
     * touch() в пределах одной секунды не меняет updated_at: модель не dirty,
     * save() не делает UPDATE и Observer молчит. На проде категории всегда
     * старше секунды — сдвигаем метку, чтобы тест воспроизводил реальность.
     */
    private function makeCategory(string $title): Category
    {
        $category = Category::create(['title' => $title, 'parent_id' => null]);

        DB::table('categories')
            ->where('id', $category->id)
            ->update(['updated_at' => now()->subMinute()]);

        return $category->fresh();
    }

    private function countEventsSince(int $outboxBefore, int $categoryId): int
    {
        return EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $categoryId)
            ->where('id', '>', $outboxBefore)
            ->count();
    }

    /**
     * @return list<string|null>
     */
    private function shopCodesSince(int $outboxBefore, int $categoryId): array
    {
        return EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $categoryId)
            ->where('id', '>', $outboxBefore)
            ->get()
            ->map(fn (EventOutbox $e) => $e->payload['shop_code'] ?? null)
            ->all();
    }

    public function test_scope_limits_publication_to_listed_shops(): void
    {
        $shopA = $this->makeShop('a');
        $shopB = $this->makeShop('b');
        $shopC = $this->makeShop('c');

        $category = $this->makeCategory('Scoped ' . uniqid());
        $category->shopCategories()->createMany([
            ['shop_id' => $shopA->id],
            ['shop_id' => $shopB->id],
            ['shop_id' => $shopC->id],
        ]);

        $outboxBefore = EventOutbox::max('id') ?? 0;

        Category::publishingOnlyForShops([$shopB->id], fn () => $category->touch());

        $codes = $this->shopCodesSince($outboxBefore, $category->id);

        $this->assertSame([$shopB->code], $codes, 'Событие должно уйти только магазину из скоупа');
    }

    /**
     * Изменение самой категории (не привязки) обязано дойти до всех магазинов —
     * скоуп не должен подменять это поведение по умолчанию.
     */
    public function test_without_scope_fanout_stays_intact(): void
    {
        $shopA = $this->makeShop('d');
        $shopB = $this->makeShop('e');

        $category = $this->makeCategory('Fanout ' . uniqid());
        $category->shopCategories()->createMany([
            ['shop_id' => $shopA->id],
            ['shop_id' => $shopB->id],
        ]);

        $outboxBefore = EventOutbox::max('id') ?? 0;

        $category->update(['title' => 'Renamed ' . uniqid()]);

        $codes = $this->shopCodesSince($outboxBefore, $category->id);

        $this->assertContains($shopA->code, $codes);
        $this->assertContains($shopB->code, $codes);
    }

    /**
     * Скоуп действует только внутри замыкания: после выхода связь снова отдаёт
     * все магазины, иначе статическое состояние протекло бы на соседний код
     * (в воркере это один процесс на тысячи событий).
     */
    public function test_scope_is_restored_after_callback(): void
    {
        $shopA = $this->makeShop('f');
        $shopB = $this->makeShop('g');

        $category = $this->makeCategory('Restore ' . uniqid());
        $category->shopCategories()->createMany([
            ['shop_id' => $shopA->id],
            ['shop_id' => $shopB->id],
        ]);

        Category::publishingOnlyForShops([$shopA->id], fn () => null);

        $this->assertCount(
            2,
            $category->fresh()->shopCategories()->get(),
            'После выхода из скоупа связь обязана отдавать все магазины'
        );
    }

    /**
     * Пустой список — сообщать некому. Callback не выполняется вовсе: иначе
     * whereIn по пустому списку оставил бы модель без связей и Observer сделал
     * бы fallback publishSingleEntity — событие без shop_code, которого
     * потребители не ждут.
     */
    public function test_empty_scope_publishes_nothing(): void
    {
        $shop = $this->makeShop('h');

        $category = $this->makeCategory('Empty ' . uniqid());
        $category->shopCategories()->create(['shop_id' => $shop->id]);

        $outboxBefore = EventOutbox::max('id') ?? 0;

        $called = false;
        Category::publishingOnlyForShops([], function () use (&$called, $category) {
            $called = true;
            $category->touch();
        });

        $this->assertFalse($called, 'Callback при пустом скоупе выполняться не должен');
        $this->assertSame(0, $this->countEventsSince($outboxBefore, $category->id));
    }

    /**
     * Привязка категорий к очередному магазину сети публикует событие только
     * ему. Регресс: без скоупа каждый новый магазин получал рассылку по всем
     * уже привязанным, то есть N(N+1)/2 событий на сеть.
     */
    public function test_attach_categories_publishes_only_for_target_shop(): void
    {
        $existingA = $this->makeShop('i');
        $existingB = $this->makeShop('j');
        $newShop = $this->makeShop('k');

        $category = $this->makeCategory('Attach ' . uniqid());
        $category->shopCategories()->createMany([
            ['shop_id' => $existingA->id],
            ['shop_id' => $existingB->id],
        ]);

        // ShopCategory::$touches = ['category'] — фикстуры выше подняли
        // categories.updated_at до now() raw-апдейтом. Сервис достаёт категорию
        // из БД заново, и touch() в ту же секунду не сделал бы её dirty: UPDATE
        // не выполнился бы и событие не появилось бы по причине, к скоупу
        // отношения не имеющей. Возвращаем метку в прошлое, как на проде.
        DB::table('categories')
            ->where('id', $category->id)
            ->update(['updated_at' => now()->subMinute()]);

        $outboxBefore = EventOutbox::max('id') ?? 0;

        app(ShopService::class)->attachCategoriesWithEvents($newShop, [$category->id]);

        $codes = $this->shopCodesSince($outboxBefore, $category->id);

        $this->assertSame(
            [$newShop->code],
            $codes,
            'Ожидали одно событие для нового магазина, получили: ' . json_encode($codes)
        );
    }
}
