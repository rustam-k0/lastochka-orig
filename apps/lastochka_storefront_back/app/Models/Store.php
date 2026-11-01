<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Store\WorkingHours;
use App\Services\Delivery\ZoneDeliveryLadder;
use App\Services\Pricing\DeliveryPriceLadder;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Store extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'source',
        'name',
        'city_id',
        'is_default',
        'image_path',
        'min_order_amount',
        'delivery_price',
        'free_delivery_from',
        'delivery_price_ranges',
        'rating',
        'reviews_count',
        'latitude',
        'longitude',
        'slot_duration',
        'is_open_24_7',
        'is_active',
        'is_b2b',
        'type',
        'is_pickup_enabled',
        'min_pickup_order_amount',
        'pickup_payment_methods',
        'pickup_auto_cancel_enabled',
        'pickup_wait_perishable_hours',
        'pickup_wait_regular_hours',
        'pickup_grace_period_hours',
        'heavy_order_threshold',
        'assembly_price',
        'address',
        'description',
        'is_bonus_enabled',
        'bonus_earn_percent',
        'bonus_spend_percent',
        'ignore_stock',
        'custom_checkboxes',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'min_order_amount' => 'integer',
            'delivery_price' => 'integer',
            'free_delivery_from' => 'integer',
            'delivery_price_ranges' => 'array',
            'rating' => 'decimal:1',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'slot_duration' => 'integer',
            'is_open_24_7' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_b2b' => 'boolean',
            'is_pickup_enabled' => 'boolean',
            'min_pickup_order_amount' => 'integer',
            'pickup_payment_methods' => 'array',
            'pickup_auto_cancel_enabled' => 'boolean',
            'pickup_wait_perishable_hours' => 'integer',
            'pickup_wait_regular_hours' => 'integer',
            'pickup_grace_period_hours' => 'integer',
            'heavy_order_threshold' => 'integer',
            'assembly_price' => 'integer',
            'is_bonus_enabled' => 'boolean',
            'bonus_earn_percent' => 'integer',
            'bonus_spend_percent' => 'integer',
            'ignore_stock' => 'boolean',
            'custom_checkboxes' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StoreSchedule::class);
    }

    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function storeProducts(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function productGroups(): HasMany
    {
        return $this->hasMany(ProductGroup::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StoreReview::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_store')
            ->withPivot(['position', 'is_active'])
            ->withTimestamps();
    }

    // ==================== Scopes ====================

    /**
     * Только активные магазины.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Фильтрация магазинов по типу пользователя (B2B/B2C).
     *
     * Поведение завязано на feature toggle модуля `b2b` в project_modules:
     *  - модуль выключен → фильтр не применяется (все юзеры видят все магазины);
     *  - модуль включён + B2B-пользователь → видит все магазины, B2B-магазины первыми;
     *  - модуль включён + B2C-пользователь или гость → видит только не-B2B магазины.
     */
    public function scopeForUserType(Builder $query, ?User $user): Builder
    {
        if (! app(ModuleConfigService::class)->isB2bEnabled()) {
            return $query;
        }

        if ($user?->is_b2b) {
            return $query->orderByDesc('is_b2b');
        }

        return $query->where('is_b2b', false);
    }

    /**
     * Только магазины с включённым самовывозом.
     */
    public function scopePickupEnabled(Builder $query): Builder
    {
        return $query->where('is_pickup_enabled', true);
    }

    /**
     * Фильтр по городу. Пустой/нулевой cityId фильтр не применяет.
     *
     * Магазины без города (city_id = null) в выдачу по конкретному городу
     * не попадают: город не задан — принадлежность неизвестна.
     */
    public function scopeInCity(Builder $query, ?int $cityId): Builder
    {
        if (! $cityId) {
            return $query;
        }

        return $query->where('city_id', $cityId);
    }

    /**
     * Только магазины по умолчанию (один на город).
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    // ==================== Working hours ====================

    /**
     * Принимает ли магазин заказы прямо сейчас (для оформления без слота,
     * модуль checkout_without_slots). Слоты неявно ограничивали заказ графиком
     * работы; без слота проверяем сами:
     *  - круглосуточный магазин → всегда открыт;
     *  - график не заведён вовсе → fail-open (считаем открытым: настройка
     *    графика — зона ответственности магазина, не блокируем продажи);
     *  - график есть, но на сегодня строки нет / без opens_at → закрыт;
     *  - иначе now ∈ [opens_at, closes_at), где closes_at 00:00 = конец суток.
     */
    public function isOpenNow(): bool
    {
        if ($this->is_open_24_7) {
            return true;
        }

        $this->loadMissing('schedules');

        if ($this->schedules->isEmpty()) {
            return true;
        }

        $now = now();
        $schedule = $this->schedules->firstWhere('day_of_week', $now->dayOfWeekIso);

        if (! $schedule || ! $schedule->opens_at) {
            return false;
        }

        $opensAt = $now->copy()->setTime((int) $schedule->opens_at->format('H'), (int) $schedule->opens_at->format('i'), 0);
        $closesAt = $now->copy()->setTime((int) $schedule->closes_at->format('H'), (int) $schedule->closes_at->format('i'), 0);

        // 00:00 закрытия = до конца суток
        if ($closesAt->lte($opensAt)) {
            $closesAt->addDay();
        }

        return $now->gte($opensAt) && $now->lt($closesAt);
    }

    // ==================== Delivery ====================

    /**
     * Лесенка стоимости доставки магазина (MID-280) для расчёта без адреса клиента:
     * ступени основной зоны из события магазина, без них прежний порог free_delivery_from.
     */
    public function deliveryLadder(): DeliveryPriceLadder
    {
        return ZoneDeliveryLadder::make(
            (int) ($this->delivery_price ?? 0),
            $this->delivery_price_ranges,
            $this->free_delivery_from,
        );
    }

    // ==================== Accessors ====================

    /**
     * Часы работы на текущий день.
     */
    public function getWorkingHoursAttribute(): ?WorkingHours
    {
        if ($this->is_open_24_7) {
            return new WorkingHours(0, 0, 24, 0);
        }

        $today = now()->dayOfWeekIso; // 1=Mon, 7=Sun
        $schedule = $this->schedules->firstWhere('day_of_week', $today);

        if (! $schedule || ! $schedule->opens_at) {
            return null;
        }

        return new WorkingHours(
            openingHour: (int) $schedule->opens_at->format('H'),
            openingMinute: (int) $schedule->opens_at->format('i'),
            closingHour: (int) $schedule->closes_at->format('H'),
            closingMinute: (int) $schedule->closes_at->format('i'),
        );
    }
}
