<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\SearchQueryLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

/**
 * Серверный лог поиска в магазине — источник отчёта «искали, но не нашли»
 * (мобилка события поиска не шлёт, поэтому пишем на бэкенде).
 *
 * Строка = один запрос: текст, магазин, клиент или устройство, total выдачи и
 * товары, которые по запросу есть в каталоге, но отсечены из-за отсутствия
 * остатка ({@see SearchService::unavailableInStore()}).
 *
 * Поиск в мобилке срабатывает на каждую букву («м», «мо», «мол»...). Запрос,
 * продолжающий предыдущий запрос того же клиента в том же магазине (один —
 * префикс другого) не позже series_window_seconds, попадает в его серию;
 * итоговым (is_final) считается самый длинный запрос серии, при равной длине
 * — последний. Отчёты считают только is_final = 1.
 */
final class SearchQueryLogger
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    /**
     * Записать запрос после отправки ответа клиенту: лог не должен ни
     * замедлять поиск, ни ломать его. $showAdult — видимость 18+ той же
     * выдачи (SearchService не синглтон, решение передаём явно).
     */
    public function recordAfterResponse(Request $request, string $query, int $storeId, int $resultsCount, bool $showAdult): void
    {
        if (! config('meilisearch.query_log.enabled', true)) {
            return;
        }

        $this->searchService->withAdultVisibility($showAdult);

        $userId = auth('sanctum')->id();
        $actorKey = $userId !== null
            ? 'u:'.$userId
            : 'd:'.sha1($request->ip().'|'.(string) $request->userAgent());
        $searchedAt = CarbonImmutable::now();

        defer(function () use ($query, $storeId, $userId, $actorKey, $resultsCount, $searchedAt): void {
            try {
                $this->record($query, $storeId, $userId !== null ? (int) $userId : null, $actorKey, $resultsCount, $searchedAt);
            } catch (\Throwable $e) {
                Log::warning('Search query log failed', [
                    'query' => $query,
                    'store_id' => $storeId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function record(
        string $query,
        int $storeId,
        ?int $userId,
        string $actorKey,
        int $resultsCount,
        CarbonImmutable $searchedAt,
    ): ?SearchQueryLog {
        $normalized = self::normalize($query);

        if ($normalized === '') {
            return null;
        }

        try {
            $unavailable = $this->searchService->unavailableInStore(
                $query,
                $storeId,
                (int) config('meilisearch.query_log.unavailable_limit', 50),
            );
        } catch (\Throwable $e) {
            // Без Meili запрос всё равно ценен: текст и total уже известны.
            Log::warning('Search query log: unavailable products lookup failed', [
                'query' => $query,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
            $unavailable = [];
        }

        return DB::transaction(function () use ($query, $normalized, $storeId, $userId, $actorKey, $resultsCount, $unavailable, $searchedAt): SearchQueryLog {
            $window = (int) config('meilisearch.query_log.series_window_seconds', 60);

            $previous = SearchQueryLog::query()
                ->where('actor_key', $actorKey)
                ->where('store_id', $storeId)
                ->where('searched_at', '>=', $searchedAt->subSeconds($window))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $seriesId = $previous !== null && self::continues($previous->query_normalized, $normalized)
                ? (int) ($previous->series_id ?? $previous->id)
                : null;

            $log = SearchQueryLog::query()->create([
                'store_id' => $storeId,
                'user_id' => $userId,
                'actor_key' => $actorKey,
                'query' => mb_substr(trim($query), 0, 255),
                'query_normalized' => $normalized,
                'results_count' => $resultsCount,
                'unavailable_product_ids' => $unavailable,
                'unavailable_count' => count($unavailable),
                'series_id' => $seriesId,
                'is_final' => true,
                'searched_at' => $searchedAt,
            ]);

            if ($seriesId === null) {
                $log->forceFill(['series_id' => $log->id])->save();

                return $log;
            }

            $this->markFinal($seriesId);

            return $log->refresh();
        });
    }

    /**
     * Нижний регистр и схлопнутые пробелы: «Молоко  » и «молоко» — один запрос.
     */
    public static function normalize(string $query): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($query)) ?? trim($query);

        return mb_substr(mb_strtolower($collapsed), 0, 255);
    }

    /**
     * Новый запрос продолжает набор предыдущего: дописал буквы, стёр часть
     * или повторил тот же текст.
     */
    private static function continues(string $previous, string $current): bool
    {
        return str_starts_with($current, $previous) || str_starts_with($previous, $current);
    }

    /**
     * Итоговый запрос серии — самый длинный, при равной длине последний.
     */
    private function markFinal(int $seriesId): void
    {
        $rows = SearchQueryLog::query()
            ->where('series_id', $seriesId)
            ->get(['id', 'query_normalized']);

        $final = $rows
            ->sort(fn (SearchQueryLog $a, SearchQueryLog $b): int => [mb_strlen($b->query_normalized), $b->id] <=> [mb_strlen($a->query_normalized), $a->id])
            ->first();

        SearchQueryLog::query()
            ->where('series_id', $seriesId)
            ->where('id', '!=', $final->id)
            ->update(['is_final' => false]);

        SearchQueryLog::query()
            ->whereKey($final->id)
            ->update(['is_final' => true]);
    }
}
