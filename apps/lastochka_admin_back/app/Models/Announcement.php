<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperAnnouncement
 */
class Announcement extends Model
{
    use HasFactory, ModelTools, HasImage, SoftDeletes;

    const table = "announcements";
    protected $table = self::table;

    protected $fillable = [
        'title',
        'short_description',
        'description',
        'promocode_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'promocode_id' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Получение связанного промокода
     */
    public function promocode(): BelongsTo
    {
        return $this->belongsTo(MerchantPromocode::class, 'promocode_id');
    }

    /**
     * Получение изображения уведомления
     */
    public function image(): HasOneThrough
    {
        return $this->hasOneThrough(
            Img::class,
            JoinImg::class,
            'item_id',
            'id',
            'id',
            'img_id'
        )->where([
            ['head_id', Status::CODE_ANNOUNCEMENT_IMG],
            ['table_name', $this->getTable()],
        ]);
    }

    /**
     * Получение связки изображения
     */
    public function joinImage(): BelongsTo
    {
        return $this->belongsTo(JoinImg::class, 'id', 'item_id')
            ->where([
                ['head_id', Status::CODE_ANNOUNCEMENT_IMG],
                ['table_name', $this->getTable()],
            ]);
    }

    /**
     * Scope: только активные уведомления
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $isActive = $args['is_active'] ?? request()->input('is_active');
        $search = $args['search'] ?? request()->input('search');
        $promocodeId = $args['promocode_id'] ?? request()->input('promocode_id');

        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('short_description', 'like', '%' . $search . '%');
            });
        }

        if ($promocodeId !== null) {
            $query->where('promocode_id', $promocodeId);
        }

        return $query;
    }

    /**
     * Scope: сортировка по умолчанию (created_at DESC)
     */
    public function scopeSorter(Builder $query, ?array $args = null): Builder
    {
        $ordering = $args['ordering'] ?? request()->input('ordering');

        if (!empty($ordering)) {
            foreach ($ordering as $order) {
                $query->orderBy($order['column'], $order['order'] ?? 'asc');
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }
}