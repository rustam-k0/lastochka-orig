<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class City extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Снять флаг «город по умолчанию» со всех городов, кроме указанного.
     *
     * Обновляем поштучно через Eloquent, а не массовым update() по билдеру:
     * массовый апдейт не поднимает модельные события, observer не кладёт
     * изменение в outbox, и в витрине остаётся второй город с is_default = 1.
     */
    public static function resetDefaultExcept(int $keepCityId): void
    {
        static::where('is_default', true)
            ->where('id', '!=', $keepCityId)
            ->get()
            ->each(fn (self $city) => $city->update(['is_default' => false]));
    }
}
