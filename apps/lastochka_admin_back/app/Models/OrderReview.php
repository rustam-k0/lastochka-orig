<?php

namespace App\Models;

use App\Enums\OrderReviewErrorType;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin IdeHelperOrderReview
 */
class OrderReview extends Model
{
    use HasFactory, ModelTools, SoftDeletes;

    const table = "order_reviews";
    protected $table = self::table;

    protected $fillable = [
        'order_id',
        'user_id',
        'rating',
        'description',
        'author_name',
        'author_phone',
        'source',
        'error_type',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'user_id' => 'integer',
        'rating' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'user_id');
    }

    /**
     * Сквозной номер заказа, к которому относится отзыв.
     */
    public function getOrderNumberAttribute(): ?string
    {
        return $this->order?->number;
    }

    /**
     * Название типа ошибки сборки ({@see OrderReviewErrorType}) или null.
     */
    public function getErrorTypeLabelAttribute(): ?string
    {
        return OrderReviewErrorType::tryFrom((string) $this->error_type)?->label();
    }

    /**
     * Scope: фильтрация по магазинам текущего пользователя
     * Админ видит всё, селлер — только отзывы на заказы своих магазинов
     */
    public function scopeForCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if (!$user || $user->isGlobalScope()) {
            return $query;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        return $query->whereHas('order', function (Builder $orderQuery) use ($shopIds) {
            $orderQuery->whereIn('shop_id', $shopIds);
        });
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $query->forCurrentUser();

        $orderId = $args['order_id'] ?? null;
        $rating = $args['rating'] ?? null;
        $ratingMin = $args['rating_min'] ?? null;
        $ratingMax = $args['rating_max'] ?? null;
        $search = $args['search'] ?? null;
        $errorType = $args['error_type'] ?? null;

        if ($orderId !== null) {
            $query->where('order_id', $orderId);
        }

        if ($rating !== null) {
            $query->where('rating', $rating);
        }

        if ($ratingMin !== null) {
            $query->where('rating', '>=', $ratingMin);
        }

        if ($ratingMax !== null) {
            $query->where('rating', '<=', $ratingMax);
        }

        if ($errorType !== null && $errorType !== '') {
            $query->where('error_type', $errorType);
        }

        if ($search !== null && $search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('description', 'like', '%' . $search . '%')
                  ->orWhere('author_name', 'like', '%' . $search . '%');
            });
        }

        return $query;
    }

    /**
     * Scope: сортировка по умолчанию (created_at DESC)
     */
    public function scopeSorter(Builder $query, ?array $args = null): Builder
    {
        $ordering = $args['ordering'] ?? null;

        // Whitelist допустимых колонок для сортировки
        $allowedColumns = [
            'id',
            'order_id',
            'rating',
            'created_at',
            'updated_at'
        ];

        $applied = false;

        foreach ((array) $ordering as $order) {
            $column = $order['orderColumn'] ?? null;
            $direction = strtolower($order['orderBy'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

            // Проверяем, что колонка в whitelist
            if ($column && in_array($column, $allowedColumns)) {
                $query->orderBy($column, $direction);
                $applied = true;
            }
        }

        if (!$applied) {
            $query->orderBy('created_at', 'desc');
        }

        // Стабильный порядок при равных значениях сортировки
        $query->orderBy('id', 'desc');

        return $query;
    }
}
