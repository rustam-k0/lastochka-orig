<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Запрос поиска в магазине — источник отчёта «искали, но не нашли».
 * Пишет {@see \App\Services\Search\SearchQueryLogger}.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $user_id
 * @property string $actor_key
 * @property string $query
 * @property string $query_normalized
 * @property int $results_count
 * @property list<int>|null $unavailable_product_ids
 * @property int $unavailable_count
 * @property int|null $series_id
 * @property bool $is_final
 * @property \Illuminate\Support\Carbon $searched_at
 */
final class SearchQueryLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'store_id',
        'user_id',
        'actor_key',
        'query',
        'query_normalized',
        'results_count',
        'unavailable_product_ids',
        'unavailable_count',
        'series_id',
        'is_final',
        'searched_at',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'user_id' => 'integer',
            'results_count' => 'integer',
            'unavailable_product_ids' => 'array',
            'unavailable_count' => 'integer',
            'series_id' => 'integer',
            'is_final' => 'boolean',
            'searched_at' => 'datetime',
        ];
    }
}
