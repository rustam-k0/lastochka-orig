<?php

declare(strict_types=1);

namespace App\Services\ProductGroup;

use App\Enums\ProductGroupFillType;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductView;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Фиксация просмотров карточки для автогруппы «Смотрел, но не купил»
 * (таблица product_views) и отметка «клал в корзину и убрал».
 *
 * Просмотр фиксируется из аналитического события product.viewed
 * авторизованного пользователя, если карточка была открыта не меньше
 * personal_view_min_duration_ms и открыта не из самой группы
 * «Смотрел, но не купил» (иначе группа сама себя подкармливала бы).
 */
final class ProductViewRecorder
{
    /**
     * @param  array<string, mixed>  $payload  Событие product.viewed (product_id, duration_ms, entry_point, product_group_id, occurred_at)
     * @return bool  true — просмотр записан
     */
    public function record(?User $user, array $payload): bool
    {
        if ($user === null) {
            return false;
        }

        $productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT);

        if ($productId === false || $productId <= 0) {
            return false;
        }

        $duration = $payload['duration_ms'] ?? null;

        if (! is_numeric($duration)
            || (float) $duration < (int) config('product_groups.personal_view_min_duration_ms', 3000)
        ) {
            return false;
        }

        if ($this->openedFromViewedGroup($payload)) {
            return false;
        }

        if (! Product::query()->whereKey($productId)->exists()) {
            return false;
        }

        $viewedAt = $this->viewedAt($payload['occurred_at'] ?? null);

        try {
            $this->upsert((int) $user->id, $productId, $viewedAt);
        } catch (UniqueConstraintViolationException) {
            // Параллельный запрос успел вставить строку — повторяем как обновление.
            $this->upsert((int) $user->id, $productId, $viewedAt);
        }

        return true;
    }

    /**
     * Отметить «клал в корзину и убрал» для уже просмотренных товаров.
     * Новые строки не создаются: сигнал только меняет порядок в группе.
     *
     * @param  array<int, int|string|null>  $productIds
     */
    public function markCartRemoved(int $userId, array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            fn (int $id) => $id > 0,
        )));

        if ($productIds === []) {
            return;
        }

        try {
            ProductView::query()
                ->where('user_id', $userId)
                ->whereIn('product_id', $productIds)
                ->update(['cart_removed_at' => now()]);
        } catch (\Throwable $e) {
            // Сигнал ранжирования не должен ломать корзину.
            Log::warning('ProductViewRecorder: cart_removed_at not saved', [
                'user_id' => $userId,
                'product_ids' => $productIds,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function upsert(int $userId, int $productId, CarbonImmutable $viewedAt): void
    {
        DB::transaction(function () use ($userId, $productId, $viewedAt): void {
            $view = ProductView::query()
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($view === null) {
                ProductView::create([
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'views_count' => 1,
                    'first_viewed_at' => $viewedAt,
                    'last_viewed_at' => $viewedAt,
                ]);

                return;
            }

            $view->views_count = $view->views_count + 1;

            // Офлайн-буфер мобилки присылает события не по порядку: метки
            // двигаем только в свою сторону.
            if ($view->last_viewed_at === null || $view->last_viewed_at->lt($viewedAt)) {
                $view->last_viewed_at = $viewedAt;
            }
            if ($view->first_viewed_at === null || $view->first_viewed_at->gt($viewedAt)) {
                $view->first_viewed_at = $viewedAt;
            }

            $view->save();
        });
    }

    /**
     * Карточка открыта из группы «Смотрел, но не купил».
     *
     * @param  array<string, mixed>  $payload
     */
    private function openedFromViewedGroup(array $payload): bool
    {
        if (($payload['entry_point'] ?? null) !== 'product_group') {
            return false;
        }

        $groupId = filter_var($payload['product_group_id'] ?? null, FILTER_VALIDATE_INT);

        if ($groupId === false || $groupId <= 0) {
            return false;
        }

        $fillType = ProductGroup::withTrashed()->whereKey($groupId)->value('fill_type');

        return ProductGroupFillType::fromValue($fillType) === ProductGroupFillType::ViewedNotBought;
    }

    /**
     * Момент просмотра: occurred_at события в часовом поясе приложения,
     * не позже текущего момента; без валидной метки — сейчас.
     */
    private function viewedAt(mixed $occurredAt): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if (! is_string($occurredAt) || $occurredAt === '') {
            return $now;
        }

        try {
            $ts = CarbonImmutable::parse($occurredAt)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return $now;
        }

        return $ts->greaterThan($now) ? $now : $ts;
    }
}
