<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Search\Contracts\SearchClientInterface;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\TimeOutException;

final class MeilisearchClient implements SearchClientInterface
{
    private Client $client;

    private string $indexName;

    public function __construct()
    {
        $this->client = new Client(
            url: config('meilisearch.host'),
            apiKey: config('meilisearch.key'),
        );

        $this->indexName = config('meilisearch.indexes.products');
    }

    public function search(string $query, array $options = []): array
    {
        return $this->withRetry(function () use ($query, $options) {
            $searchParams = [];

            if (isset($options['filter'])) {
                $searchParams['filter'] = $options['filter'];
            }

            if (isset($options['limit'])) {
                $searchParams['limit'] = $options['limit'];
            }

            if (isset($options['offset'])) {
                $searchParams['offset'] = $options['offset'];
            }

            if (isset($options['attributesToRetrieve'])) {
                $searchParams['attributesToRetrieve'] = $options['attributesToRetrieve'];
            }

            $searchParams['matchingStrategy'] = $options['matchingStrategy'] ?? 'all';

            $result = $this->client->index($this->indexName)->search($query, $searchParams);

            return [
                'hits' => $result->getHits(),
                'estimatedTotalHits' => $result->getEstimatedTotalHits(),
                'processingTimeMs' => $result->getProcessingTimeMs(),
            ];
        });
    }

    public function indexDocument(array $document): void
    {
        $this->indexDocuments([$document]);
    }

    public function indexDocuments(array $documents): void
    {
        $this->withRetry(function () use ($documents) {
            $this->ensureIndexExists();
            $task = $this->client->index($this->indexName)->addDocuments($documents, 'id');
            $this->client->waitForTask($task['taskUid'], 5000, 100);
        });
    }

    public function deleteDocument(int $id): void
    {
        $this->withRetry(function () use ($id) {
            try {
                $task = $this->client->index($this->indexName)->deleteDocument($id);
                $this->client->waitForTask($task['taskUid'], 5000, 100);
            } catch (\Exception $e) {
                // Игнорируем если документ не существует
                Log::debug('Document not found for deletion', ['id' => $id]);
            }
        });
    }

    public function deleteDocuments(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $this->withRetry(function () use ($ids) {
            $task = $this->client->index($this->indexName)->deleteDocuments($ids);
            $this->client->waitForTask($task['taskUid'], 10000, 100);
        });
    }

    public function resetIndex(): void
    {
        $this->withRetry(function () {
            try {
                $task = $this->client->deleteIndex($this->indexName);
                $this->client->waitForTask($task['taskUid'], 10000, 100);
            } catch (\Exception $e) {
                // Индекс не существовал
                Log::debug('Index did not exist before reset', ['index' => $this->indexName]);
            }

            $this->ensureIndexExists();
            $this->updateIndexSettings();
        });
    }

    public function updateIndexSettings(): void
    {
        $this->withRetry(function () {
            $this->ensureIndexExists();

            $settings = config('meilisearch.products_index');
            $index = $this->client->index($this->indexName);

            $task = $index->updateSettings($settings);
            $this->client->waitForTask($task['taskUid'], 30000, 100);
        });
    }

    public function isHealthy(): bool
    {
        try {
            $this->client->health();

            return true;
        } catch (\Exception $e) {
            Log::warning('Meilisearch health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function ensureIndexExists(): void
    {
        try {
            $this->client->index($this->indexName)->fetchRawInfo();
        } catch (\Exception $e) {
            // Индекс не существует, создаём
            $task = $this->client->createIndex($this->indexName, ['primaryKey' => 'id']);
            $this->client->waitForTask($task['taskUid'], 10000, 100);
        }
    }

    /**
     * Выполнить операцию с retry при сетевых ошибках.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withRetry(callable $operation): mixed
    {
        $maxAttempts = config('meilisearch.retry.max_attempts', 3);
        $baseDelayMs = config('meilisearch.retry.base_delay_ms', 100);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (CommunicationException|TimeOutException $e) {
                $lastException = $e;

                if ($attempt < $maxAttempts) {
                    $delay = $baseDelayMs * pow(2, $attempt - 1);
                    usleep($delay * 1000);

                    Log::warning('Meilisearch operation retry', [
                        'attempt' => $attempt,
                        'delay_ms' => $delay,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::error('Meilisearch operation failed after retries', [
            'max_attempts' => $maxAttempts,
            'error' => $lastException?->getMessage(),
        ]);

        throw $lastException;
    }
}
