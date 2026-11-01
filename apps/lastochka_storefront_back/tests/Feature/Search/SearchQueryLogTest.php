<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Product;
use App\Models\SearchQueryLog;
use App\Models\Store;
use App\Models\User;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Серверный лог поиска в магазине (отчёт «искали, но не нашли»):
 * строка на запрос, товары без остатка, склейка серии набора по буквам.
 */
final class SearchQueryLogTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    private Store $store;

    private Product $inStock;

    private Product $outOfStock;

    protected function setUp(): void
    {
        parent::setUp();

        // Лог пишется в defer() после ответа — в тестах выполняем сразу.
        $this->withoutDefer();

        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);
        $this->searchClient->resetIndex();

        $this->store = Store::factory()->create();

        $this->inStock = $this->product('Молоко Простоквашино', quantity: 10);
        $this->outOfStock = $this->product('Молоко Домик в деревне', quantity: 0);
        // Снят с продажи — не «нет в наличии», а нет в каталоге.
        $this->product('Молоко Снятое', quantity: 0, active: false);
        $this->product('Хлеб Бородинский', quantity: 5);
    }

    public function test_logs_query_with_results_and_out_of_stock_products(): void
    {
        $this->search('Молоко')->assertOk()->assertJsonPath('total', 1);

        $log = SearchQueryLog::query()->sole();

        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame('Молоко', $log->query);
        $this->assertSame('молоко', $log->query_normalized);
        $this->assertSame(1, $log->results_count);
        $this->assertSame([$this->outOfStock->id], $log->unavailable_product_ids);
        $this->assertSame(1, $log->unavailable_count);
        $this->assertTrue($log->is_final);
        $this->assertSame($log->id, $log->series_id);
        $this->assertNull($log->user_id);
        $this->assertStringStartsWith('d:', $log->actor_key);
    }

    public function test_query_without_results_is_logged_with_zero(): void
    {
        $this->search('Кефир')->assertOk()->assertJsonPath('total', 0);

        $log = SearchQueryLog::query()->sole();

        $this->assertSame(0, $log->results_count);
        $this->assertSame([], $log->unavailable_product_ids);
    }

    public function test_typing_series_keeps_only_longest_query_final(): void
    {
        $this->search('мо');
        $this->search('мол');
        $this->search('молоко');
        $this->search('молоко'); // повтор того же запроса — та же серия

        $logs = SearchQueryLog::query()->orderBy('id')->get();

        $this->assertCount(4, $logs);
        $this->assertCount(1, $logs->pluck('series_id')->unique());
        $this->assertSame([false, false, false, true], $logs->pluck('is_final')->all());
    }

    public function test_erasing_back_keeps_longest_query_final(): void
    {
        $this->search('молоко');
        $this->search('мол');

        $logs = SearchQueryLog::query()->orderBy('id')->get();

        $this->assertSame([true, false], $logs->pluck('is_final')->all());
        $this->assertSame($logs[0]->series_id, $logs[1]->series_id);
    }

    public function test_unrelated_query_starts_new_series(): void
    {
        $this->search('молоко');
        $this->search('хлеб');

        $logs = SearchQueryLog::query()->orderBy('id')->get();

        $this->assertSame([true, true], $logs->pluck('is_final')->all());
        $this->assertNotSame($logs[0]->series_id, $logs[1]->series_id);
    }

    public function test_pause_longer_than_window_starts_new_series(): void
    {
        $this->search('мол');
        $this->travel(61)->seconds();
        $this->search('молоко');

        $logs = SearchQueryLog::query()->orderBy('id')->get();

        $this->assertSame([true, true], $logs->pluck('is_final')->all());
        $this->assertNotSame($logs[0]->series_id, $logs[1]->series_id);
    }

    public function test_series_are_split_by_client(): void
    {
        $this->search('мол', ['REMOTE_ADDR' => '10.0.0.1']);
        $this->search('молоко', ['REMOTE_ADDR' => '10.0.0.2']);

        $this->assertSame([true, true], SearchQueryLog::query()->orderBy('id')->pluck('is_final')->all());
    }

    public function test_authorized_client_is_logged_by_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->search('Молоко');

        $log = SearchQueryLog::query()->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('u:'.$user->id, $log->actor_key);
    }

    public function test_paging_count_only_and_screen_filters_are_not_logged(): void
    {
        $query = urlencode('Молоко');
        $base = "/api/v1/search/stores/{$this->store->id}/products?query={$query}";

        $this->getJson($base.'&page=2')->assertOk();
        $this->getJson($base.'&countOnly=1')->assertOk();
        $this->getJson($base.'&categoryId=5')->assertOk();
        $this->getJson($base.'&priceMin=10')->assertOk();
        $this->getJson($base.'&onlyDiscounted=1')->assertOk();

        $this->assertSame(0, SearchQueryLog::query()->count());
    }

    public function test_disabled_log_writes_nothing(): void
    {
        config(['meilisearch.query_log.enabled' => false]);

        $this->search('Молоко')->assertOk();

        $this->assertSame(0, SearchQueryLog::query()->count());
    }

    /**
     * @param  array<string, string>  $server
     */
    private function search(string $query, array $server = []): \Illuminate\Testing\TestResponse
    {
        $encoded = urlencode($query);

        return $this->withServerVariables($server)
            ->getJson("/api/v1/search/stores/{$this->store->id}/products?query={$encoded}&page=1&perPage=20");
    }

    private function product(string $title, int $quantity, bool $active = true): Product
    {
        $product = Product::factory()->for($this->store)->create([
            'title' => $title,
            'quantity' => $quantity,
            'quantity_step' => 1,
            'price' => 100,
            'is_active' => $active,
        ]);

        $this->searchService->indexProduct($product);

        return $product;
    }
}
