<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Search\Contracts\SearchClientInterface;

/**
 * Fake клиент для тестирования без Meilisearch.
 * Хранит документы в памяти и выполняет простой поиск по title.
 */
final class FakeSearchClient implements SearchClientInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $documents = [];

    /** Счётчики вызовов — тесты проверяют per-item vs bulk индексацию. */
    public int $indexDocumentCalls = 0;

    public int $indexDocumentsCalls = 0;

    /**
     * Тестовый хук: имитировать нестабильный offset-снапшот Meili — между
     * запросом за одну пачку и следующей индекс мог измениться (reindex),
     * из-за чего последний id предыдущей пачки «сползает» в начало следующей.
     * Используется тестами дедупликации {@see \App\Services\Search\SearchService::collectValidHits()}.
     */
    public bool $simulateOffsetDrift = false;

    /** Последняя отданная (с учётом дрейфа) пачка хитов — для {@see $simulateOffsetDrift}. */
    private array $lastPageHits = [];

    public function search(string $query, array $options = []): array
    {
        $query = mb_strtolower(trim($query));
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $matchAll = ($options['matchingStrategy'] ?? 'all') === 'all';
        $hits = [];

        foreach ($this->documents as $doc) {
            $title = mb_strtolower($doc['title'] ?? '');
            $sku = mb_strtolower($doc['sku'] ?? '');
            $searchable = $title.' '.$sku;

            $matchedWords = 0;
            foreach ($words as $word) {
                if (str_contains($searchable, $word)) {
                    $matchedWords++;
                }
            }

            $matches = $matchAll
                ? $matchedWords === count($words)
                : $matchedWords > 0;

            if ($matches) {
                // Применяем фильтры
                if (isset($options['filter']) && ! $this->matchesFilter($doc, $options['filter'])) {
                    continue;
                }

                $hits[] = $doc;
            }
        }

        // Применяем offset и limit
        $offset = $options['offset'] ?? 0;
        $limit = $options['limit'] ?? 20;
        $total = count($hits);
        $pageHits = array_slice($hits, $offset, $limit);

        if ($this->simulateOffsetDrift && $offset > 0 && ! empty($this->lastPageHits)) {
            // Имитация нестабильного снапшота: последний хит предыдущей пачки
            // «сползает» в начало текущей (как будто reindex сдвинул позиции
            // между двумя запросами одной страницы выдачи).
            array_unshift($pageHits, end($this->lastPageHits));
            $pageHits = array_slice($pageHits, 0, $limit);
        }

        $this->lastPageHits = $pageHits;

        if (isset($options['attributesToRetrieve'])) {
            $fields = array_flip($options['attributesToRetrieve']);
            $pageHits = array_map(
                static fn (array $doc): array => array_intersect_key($doc, $fields),
                $pageHits
            );
        }

        return [
            'hits' => array_values($pageHits),
            'estimatedTotalHits' => $total,
            'processingTimeMs' => 1,
        ];
    }

    public function indexDocument(array $document): void
    {
        $this->indexDocumentCalls++;
        $this->documents[$document['id']] = $document;
    }

    public function indexDocuments(array $documents): void
    {
        $this->indexDocumentsCalls++;
        foreach ($documents as $doc) {
            $this->documents[$doc['id']] = $doc;
        }
    }

    public function deleteDocument(int $id): void
    {
        unset($this->documents[$id]);
    }

    public function deleteDocuments(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->documents[$id]);
        }
    }

    public function resetIndex(): void
    {
        $this->documents = [];
        $this->lastPageHits = [];
    }

    /**
     * Тестовый помощник: проверить, есть ли документ в индексе.
     */
    public function hasDocument(int $id): bool
    {
        return isset($this->documents[$id]);
    }

    /**
     * Тестовый помощник: получить проиндексированный документ по id.
     *
     * @return array<string, mixed>|null
     */
    public function indexedDocument(int $id): ?array
    {
        return $this->documents[$id] ?? null;
    }

    public function updateIndexSettings(): void
    {
        // No-op для fake клиента
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * Простой парсер фильтров Meilisearch.
     * Поддерживает: field = value, field > value, field IN [v1,v2,...], NOT <условие>, AND
     */
    private function matchesFilter(array $doc, string $filter): bool
    {
        // Разбиваем по AND
        $conditions = preg_split('/\s+AND\s+/i', $filter);

        foreach ($conditions as $condition) {
            $condition = trim($condition);

            // Парсим NOT <условие>. Как в Meili: документ без поля под NOT проходит
            if (preg_match('/^NOT\s+(.+)$/i', $condition, $matches)) {
                if ($this->matchesFilter($doc, $matches[1])) {
                    return false;
                }

                continue;
            }

            // Парсим field IN [v1, v2, ...]
            if (preg_match('/^(\w+)\s+IN\s+\[([^\]]*)\]$/i', $condition, $matches)) {
                $field = $matches[1];
                $rawValues = trim($matches[2]);
                $allowed = array_filter(
                    array_map(
                        fn ($v) => trim($v, " \t\n\r\0\x0B\"'"),
                        explode(',', $rawValues)
                    ),
                    fn ($v) => $v !== ''
                );
                $allowed = array_map(
                    fn ($v) => is_numeric($v) ? (int) $v : $v,
                    $allowed
                );

                if (empty($allowed)) {
                    return false;
                }

                if (! isset($doc[$field]) || ! in_array($doc[$field], $allowed, true)) {
                    return false;
                }

                continue;
            }

            // Парсим field = value
            if (preg_match('/^(\w+)\s*=\s*(.+)$/', $condition, $matches)) {
                $field = $matches[1];
                $value = trim($matches[2], '"\'');

                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = (int) $value;
                }

                if (! isset($doc[$field])) {
                    return false;
                }

                // Meili-семантика для массивных полей (category_ids = N): «содержит»
                if (is_array($doc[$field])) {
                    if (! in_array($value, $doc[$field], true)) {
                        return false;
                    }
                } elseif ($doc[$field] !== $value) {
                    return false;
                }

                continue;
            }

            // Парсим field > value
            if (preg_match('/^(\w+)\s*>\s*(.+)$/', $condition, $matches)) {
                $field = $matches[1];
                $value = (int) trim($matches[2]);

                if (! isset($doc[$field]) || $doc[$field] <= $value) {
                    return false;
                }

                continue;
            }
        }

        return true;
    }
}
