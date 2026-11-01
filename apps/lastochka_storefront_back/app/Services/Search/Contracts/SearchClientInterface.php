<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

interface SearchClientInterface
{
    /**
     * Поиск товаров в индексе.
     *
     * @param  array{
     *     filter?: string,
     *     limit?: int,
     *     offset?: int,
     *     attributesToRetrieve?: array<string>,
     * }  $options
     * @return array{
     *     hits: array<array{id: int, store_id: int, title: string, ...}>,
     *     estimatedTotalHits: int,
     *     processingTimeMs: int,
     * }
     */
    public function search(string $query, array $options = []): array;

    /**
     * Добавить или обновить документ в индексе.
     *
     * @param  array<string, mixed>  $document
     */
    public function indexDocument(array $document): void;

    /**
     * Добавить или обновить несколько документов.
     *
     * @param  array<array<string, mixed>>  $documents
     */
    public function indexDocuments(array $documents): void;

    /**
     * Удалить документ из индекса.
     */
    public function deleteDocument(int $id): void;

    /**
     * Удалить несколько документов из индекса.
     *
     * @param  array<int>  $ids
     */
    public function deleteDocuments(array $ids): void;

    /**
     * Удалить все документы и пересоздать индекс.
     */
    public function resetIndex(): void;

    /**
     * Применить настройки индекса (searchableAttributes, filterableAttributes и т.д.).
     */
    public function updateIndexSettings(): void;

    /**
     * Проверить доступность Meilisearch.
     */
    public function isHealthy(): bool;
}
