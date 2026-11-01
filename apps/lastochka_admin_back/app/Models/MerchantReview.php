<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin IdeHelperMerchantReview
 */
class MerchantReview extends Model
{
    use HasFactory, ModelTools, SoftDeletes;

    const table = "merchant_reviews";
    protected $table = self::table;

    protected $fillable = [
        'shop_id',
        'rating',
        'description',
        'is_published',
        'author_name',
        'author_phone',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'rating' => 'integer',
        'is_published' => 'boolean',
    ];

    protected $attributes = [
        'is_published' => false,
    ];

    /**
     * Получение магазина
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Scope: фильтрация по магазинам текущего пользователя
     * Админ видит всё, селлер — только свои магазины
     */
    public function scopeForCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if (!$user || $user->isGlobalScope()) {
            return $query;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        return $query->whereIn('shop_id', $shopIds);
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $query->forCurrentUser();

        $shopId = $args['shop_id'] ?? request()->input('shop_id');
        $isPublished = $args['is_published'] ?? request()->input('is_published');
        $rating = $args['rating'] ?? request()->input('rating');
        $ratingMin = $args['rating_min'] ?? request()->input('rating_min');
        $ratingMax = $args['rating_max'] ?? request()->input('rating_max');
        $search = $args['search'] ?? request()->input('search');

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        if ($isPublished !== null) {
            $query->where('is_published', filter_var($isPublished, FILTER_VALIDATE_BOOLEAN));
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

        if ($search !== null && $search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('description', 'like', '%' . $search . '%')
                  ->orWhere('author_name', 'like', '%' . $search . '%')
                  ->orWhereHas('shop', function (Builder $s) use ($search) {
                      $s->where('title', 'like', '%' . $search . '%');
                  });
            });
        }

        return $query;
    }

    /**
     * Scope: сортировка по умолчанию (created_at DESC).
     *
     * orderColumn ограничен whitelist'ом: произвольная строка ушла бы в SQL
     * как имя колонки (ORDER BY shop → Internal server error). Сортируемая
     * relation-колонка фронта (shop) маппится на JOIN по title.
     */
    public function scopeSorter(Builder $query, ?array $args = null): Builder
    {
        $ordering = $args['ordering'] ?? request()->input('ordering');

        $allowedColumns = ['id', 'rating', 'is_published', 'created_at', 'updated_at'];
        $joinedShops = false;
        $applied = false;

        foreach ((array) $ordering as $order) {
            $column = $order['orderColumn'] ?? null;
            $direction = strtolower($order['orderBy'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

            if ($column === 'shop') {
                if (!$joinedShops) {
                    $query->leftJoin('shops', 'shops.id', '=', 'merchant_reviews.shop_id');
                    $joinedShops = true;
                }
                $query->orderBy('shops.title', $direction);
                $applied = true;
            } elseif (in_array($column, $allowedColumns, true)) {
                $query->orderBy('merchant_reviews.' . $column, $direction);
                $applied = true;
            }
            // неизвестные колонки молча игнорируются
        }

        if (!$applied) {
            $query->orderBy('merchant_reviews.created_at', 'desc');
        }

        // Стабильный порядок при равных значениях сортировки
        $query->orderBy('merchant_reviews.id', 'desc');

        // JOIN не должен затирать колонки отзыва
        if ($joinedShops) {
            $query->select('merchant_reviews.*');
        }

        return $query;
    }
}
