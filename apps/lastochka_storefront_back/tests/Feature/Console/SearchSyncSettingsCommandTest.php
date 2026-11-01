<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Search\Contracts\SearchClientInterface;
use RuntimeException;
use Tests\TestCase;

final class SearchSyncSettingsCommandTest extends TestCase
{
    public function test_applies_settings_when_meilisearch_is_healthy(): void
    {
        $client = new SpyFakeSearchClient();
        $this->app->instance(SearchClientInterface::class, $client);

        $this->artisan('search:sync-settings')
            ->expectsOutputToContain('Настройки индекса применены')
            ->assertExitCode(0);

        $this->assertSame(1, $client->updateSettingsCallCount);
    }

    public function test_fails_when_meilisearch_is_unhealthy(): void
    {
        $client = new SpyFakeSearchClient(healthy: false);
        $this->app->instance(SearchClientInterface::class, $client);

        $this->artisan('search:sync-settings')
            ->expectsOutputToContain('Meilisearch недоступен')
            ->assertExitCode(1);

        $this->assertSame(0, $client->updateSettingsCallCount);
    }

    public function test_fails_when_update_throws(): void
    {
        $client = new SpyFakeSearchClient(throwOnUpdate: true);
        $this->app->instance(SearchClientInterface::class, $client);

        $this->artisan('search:sync-settings')
            ->expectsOutputToContain('Не удалось применить настройки индекса')
            ->assertExitCode(1);
    }
}

final class SpyFakeSearchClient implements SearchClientInterface
{
    public int $updateSettingsCallCount = 0;

    public function __construct(
        private readonly bool $healthy = true,
        private readonly bool $throwOnUpdate = false,
    ) {}

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function updateIndexSettings(): void
    {
        $this->updateSettingsCallCount++;

        if ($this->throwOnUpdate) {
            throw new RuntimeException('boom');
        }
    }

    public function search(string $query, array $options = []): array
    {
        return ['hits' => [], 'estimatedTotalHits' => 0, 'processingTimeMs' => 0];
    }

    public function indexDocument(array $document): void {}

    public function indexDocuments(array $documents): void {}

    public function deleteDocument(int $id): void {}

    public function deleteDocuments(array $ids): void {}

    public function resetIndex(): void {}
}
