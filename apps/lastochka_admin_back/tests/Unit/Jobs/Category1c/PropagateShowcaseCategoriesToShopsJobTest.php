<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Category1c;

use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Shop;
use App\Services\ShopService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PropagateShowcaseCategoriesToShopsJobTest extends TestCase
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

    /**
     * Категория, созданная «в прошлом»: touch() на модели с updated_at = now()
     * не делает её dirty, save() не выполняет UPDATE и Observer не публикует
     * событие. На проде категории всегда старше секунды — сдвигаем метку, чтобы
     * тест воспроизводил реальное поведение outbox.
     */
    private function makeCategory(string $title, ?int $parentId = null): Category
    {
        $category = Category::create(['title' => $title, 'parent_id' => $parentId]);

        DB::table('categories')
            ->where('id', $category->id)
            ->update(['updated_at' => now()->subMinute()]);

        return $category->fresh();
    }

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    public function test_attaches_showcases_to_all_shops(): void
    {
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));

        $this->assertTrue($shopA->categories()->where('categories.id', $showcase->id)->exists());
        $this->assertTrue($shopB->categories()->where('categories.id', $showcase->id)->exists());
    }

    public function test_is_idempotent(): void
    {
        $shop = $this->makeShop('shop-' . uniqid());
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));
        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));

        $this->assertSame(
            1,
            $shop->categories()->where('categories.id', $showcase->id)->count()
        );
    }

    public function test_only_shop_id_limits_scope(): void
    {
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id], $shopA->id))->handle(app(ShopService::class));

        $this->assertTrue($shopA->categories()->where('categories.id', $showcase->id)->exists());
        $this->assertFalse($shopB->categories()->where('categories.id', $showcase->id)->exists());
    }

    /**
     * Раскатка витрины по сети публикует РОВНО одно outbox-событие на магазин.
     *
     * Регресс, из-за которого мутация привязки падала в 504: attach+touch внутри
     * цикла по магазинам давал publishMultiEntity по всем уже существующим связям,
     * то есть 1+2+...+N = N(N+1)/2 событий на категорию (при 824 магазинах — ~340k).
     */
    public function test_publishes_one_event_per_shop_not_quadratic(): void
    {
        $this->makeShop('shop-a-' . uniqid());
        $this->makeShop('shop-b-' . uniqid());
        $this->makeShop('shop-c-' . uniqid());

        $showcase = $this->makeCategory('Showcase ' . uniqid());

        $shopsCount = Shop::query()->count();
        $this->assertGreaterThan(1, $shopsCount, 'Нужно ≥2 магазина, иначе квадратичность неотличима от линейной');

        $outboxBefore = EventOutbox::max('id') ?? 0;

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));

        $events = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $showcase->id)
            ->where('id', '>', $outboxBefore)
            ->count();

        $this->assertSame($shopsCount, $events);
    }

    /**
     * Магазины сети заводятся по одному (ShopObserver::created → Job с
     * $onlyShopId), а не одной раскаткой. Каждый прогон обязан публиковать
     * событие ТОЛЬКО для своего магазина.
     *
     * Регресс MID-диск-2026-08-10: touch() публикует по событию на КАЖДУЮ
     * существующую связь shopCategories, поэтому i-й магазин давал i событий,
     * а импорт N магазинов — 1+2+...+N = N(N+1)/2. На проде 654 магазина за
     * час дали ~29k событий на две категории, забили диск логами и binlog'ами
     * и уронили RabbitMQ по disk alarm. Внутри одного прогона Job квадратичности
     * нет (см. тест выше) — она возникает между прогонами.
     */
    public function test_sequential_shop_creation_publishes_linearly(): void
    {
        $showcase = $this->makeCategory('Showcase ' . uniqid());

        $newShops = 6;
        $outboxBefore = EventOutbox::max('id') ?? 0;

        for ($i = 0; $i < $newShops; $i++) {
            $shop = $this->makeShop('seq-' . $i . '-' . uniqid());

            // На проде между заведением магазинов проходят секунды, поэтому
            // touch() каждый раз реально меняет updated_at. В тесте прогоны
            // укладываются в одну секунду — сдвигаем метку, иначе модель не
            // dirty, Observer молчит и регресс маскируется.
            DB::table('categories')
                ->where('id', $showcase->id)
                ->update(['updated_at' => now()->subMinute()]);

            (new PropagateShowcaseCategoriesToShopsJob([$showcase->id], $shop->id))
                ->handle(app(ShopService::class));
        }

        $events = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $showcase->id)
            ->where('id', '>', $outboxBefore)
            ->count();

        $this->assertSame(
            $newShops,
            $events,
            "Ожидали по одному событию на заведённый магазин ({$newShops}), получили {$events}."
            . ' Рост быстрее линейного = фан-аут publishMultiEntity по всем связям.'
        );
    }

    /**
     * Событие уходит по каждому магазину сети — storefront должен привязать
     * витрину к своему store, а shop_code берётся из связи shopCategories.shop.
     */
    public function test_event_published_for_every_shop_code(): void
    {
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());

        $showcase = $this->makeCategory('Showcase ' . uniqid());

        $outboxBefore = EventOutbox::max('id') ?? 0;

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));

        $shopCodes = EventOutbox::where('entity_type', 'categories')
            ->where('entity_id', $showcase->id)
            ->where('id', '>', $outboxBefore)
            ->get()
            ->map(fn (EventOutbox $e) => $e->payload['shop_code'] ?? null)
            ->all();

        $this->assertContains($shopA->code, $shopCodes);
        $this->assertContains($shopB->code, $shopCodes);
    }

    /**
     * Инвариант shops_categories: pivot содержит полную ветку от корня.
     * Bulk-привязка обязана дотягивать предков так же, как syncWithoutDetaching.
     */
    public function test_attaches_ancestors_of_showcase(): void
    {
        $shop = $this->makeShop('shop-' . uniqid());

        $root = $this->makeCategory('Root ' . uniqid());
        $leaf = $this->makeCategory('Leaf ' . uniqid(), $root->id);

        (new PropagateShowcaseCategoriesToShopsJob([$leaf->id]))->handle(app(ShopService::class));

        $this->assertTrue($shop->categories()->where('categories.id', $leaf->id)->exists());
        $this->assertTrue($shop->categories()->where('categories.id', $root->id)->exists());
    }

    public function test_empty_ids_is_noop(): void
    {
        $shop = $this->makeShop('shop-' . uniqid());

        (new PropagateShowcaseCategoriesToShopsJob([]))->handle(app(ShopService::class));

        $this->assertSame(0, $shop->categories()->count());
    }
}
