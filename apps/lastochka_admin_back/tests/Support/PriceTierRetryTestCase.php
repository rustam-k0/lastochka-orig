<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Product;
use App\Models\Shop;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ModuleConfigService;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * База тестов повтора записи лесенки после deadlock (MID-308).
 *
 * DatabaseTransactions здесь нельзя: трейт открывает внешнюю транзакцию, а
 * Laravel повторяет DB::transaction($callback, 3) только на верхнем уровне
 * (вложенная транзакция пробрасывает deadlock наружу). Поэтому тест пишет в
 * БД по-настоящему и сам убирает за собой в tearDown, а блоб project_modules
 * возвращает в исходное состояние.
 *
 * Deadlock имитируется: слушатель запросов один раз бросает QueryException с
 * текстом MySQL-deadlock на первом запросе, содержащем заданный фрагмент SQL.
 */
abstract class PriceTierRetryTestCase extends TestCase
{
    protected Shop $shop;
    protected Product $product;
    protected ProductShopPriceService $tiers;

    /** @var array<int, int> */
    private array $shopIds = [];

    private ?string $originalModules = null;

    private bool $modulesTouched = false;

    private int $deadlocksLeft = 0;

    protected int $matchedQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->makeShop();
        $this->product = Product::withoutEvents(fn () => Product::create([
            'title' => 'Retry product ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
        ]));
        $this->tiers = app(ProductShopPriceService::class);
    }

    protected function tearDown(): void
    {
        // Имитация deadlock могла не сработать в теле теста (красный прогон): без
        // сброса она уронила бы запрос уборки ниже и оставила строки в БД.
        $this->deadlocksLeft = 0;

        // Таблицы может ещё не быть (красный прогон до миграции): остальную
        // уборку это не должно срывать.
        if (Schema::hasTable('price_tiers')) {
            DB::table('price_tiers')->where('product_id', $this->product->id)->delete();
        }

        DB::table('prices')->where('product_id', $this->product->id)->delete();
        DB::table('remains')->where('product_id', $this->product->id)->delete();
        DB::table('event_outbox')->where('entity_type', 'products')->where('entity_id', $this->product->id)->delete();
        DB::table('products')->where('id', $this->product->id)->delete();
        DB::table('shops')->whereIn('id', $this->shopIds)->delete();

        if ($this->modulesTouched) {
            DB::table('admin_settings')
                ->where('key', ModuleConfigService::PROJECT_MODULES_KEY)
                ->update(['value' => $this->originalModules]);
        }

        ModuleConfigService::invalidateCache();
        Cache::forget(NetworkCatalogMode::CACHE_KEY);

        parent::tearDown();
    }

    protected function makeShop(): Shop
    {
        $shop = Shop::withoutEvents(fn () => Shop::create([
            'title' => 'Retry shop',
            'code' => 'tier-retry-' . uniqid(),
        ]));
        $this->shopIds[] = $shop->id;

        return $shop;
    }

    /** Включить модуль без DatabaseTransactions: исходный блоб вернёт tearDown. */
    protected function enablePriceTiers(): void
    {
        $raw = DB::table('admin_settings')->where('key', ModuleConfigService::PROJECT_MODULES_KEY)->value('value');

        if (!$this->modulesTouched) {
            $this->originalModules = $raw;
            $this->modulesTouched = true;
        }

        $modules = $raw ? (json_decode($raw, true) ?: []) : [];
        $modules[ModuleConfigService::PRICE_TIERS_KEY] = ['label' => 'Цены от количества', 'enabled' => true];

        DB::table('admin_settings')
            ->where('key', ModuleConfigService::PROJECT_MODULES_KEY)
            ->update(['value' => json_encode($modules, JSON_UNESCAPED_UNICODE)]);

        ModuleConfigService::invalidateCache();
    }

    /**
     * Один раз уронить первый запрос с фрагментом $sqlFragment имитацией
     * deadlock. $this->matchedQueries считает все такие запросы (с повтором).
     */
    protected function deadlockOnceOn(string $sqlFragment): void
    {
        $this->deadlocksLeft = 1;
        $this->matchedQueries = 0;

        DB::listen(function (QueryExecuted $query) use ($sqlFragment) {
            if (mb_strpos($query->sql, $sqlFragment) === false) {
                return;
            }

            $this->matchedQueries++;

            if ($this->deadlocksLeft > 0) {
                $this->deadlocksLeft--;

                throw new QueryException(
                    $query->sql,
                    $query->bindings,
                    new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction')
                );
            }
        });
    }
}
