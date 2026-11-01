<?php

namespace App\Filters;

use App\Models\Status;

/**
 * Фильтры списка магазинов (shops / shops_paginate).
 *
 * Резолвится generic-скоупом ModelTools::scopeFilter по имени класса
 * (Shop → ShopFilter). Наследует базовые методы QueryFilter (title, status_id
 * и т.д.); ниже — специфичные для раздела «Магазины» вкладками фильтры.
 *
 * Важно: QueryFilter::apply() пропускает только значения null — bool false
 * доходит до метода, поэтому active=false / delivery_enabled=false /
 * pickup_enabled=false работают штатно.
 */
class ShopFilter extends QueryFilter
{
    /**
     * Поиск по названию, адресу или коду магазина (LIKE, без регистрозависимости
     * на уровне collation БД). OR-условия сгруппированы в замыкание, чтобы не
     * «утекать» через AND и не ломать остальные фильтры (active, pickup и т.д.).
     */
    public function search(string $value): void
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('title', 'LIKE', "%$value%")
                ->orWhere('address', 'LIKE', "%$value%")
                ->orWhere('code', 'LIKE', "%$value%");
        });
    }

    /**
     * Фильтр по активности магазина.
     * true  → только активные (status_id = 1);
     * false → только неактивные (status_id != 1).
     */
    public function active(bool $value): void
    {
        if ($value) {
            $this->builder->where('status_id', Status::CODE_ACTIVE);
        } else {
            $this->builder->where('status_id', '!=', Status::CODE_ACTIVE);
        }
    }

    /**
     * Фильтр по включённой доставке = наличию хотя бы одной активной зоны.
     * true  → есть активная зона доставки (whereHas zones is_active=1);
     * false → нет ни одной активной зоны (whereDoesntHave) — сюда попадают и
     *         магазины вовсе без зон, и магазины только с выключенными зонами.
     *
     * ShopZone в admin_back без глобального active-скоупа — фильтр по is_active
     * задаётся явно. SoftDeletes зоны учитывается автоматически (whereHas
     * уважает глобальный SoftDeletingScope модели).
     */
    public function delivery_enabled(bool $value): void
    {
        $activeZone = fn ($query) => $query->where('is_active', 1);

        if ($value) {
            $this->builder->whereHas('zones', $activeZone);
        } else {
            $this->builder->whereDoesntHave('zones', $activeZone);
        }
    }

    /**
     * Фильтр по включённому самовывозу (колонка is_pickup_enabled).
     * true → is_pickup_enabled = 1; false → is_pickup_enabled = 0.
     */
    public function pickup_enabled(bool $value): void
    {
        $this->builder->where('is_pickup_enabled', $value);
    }

    /**
     * Только магазины с указанными id. Нужен селекту магазина: выбранный
     * магазин подгружается по id, даже если не попал в первую страницу списка.
     * Пустой массив QueryFilter::apply() не передаёт — фильтр не применяется.
     */
    public function ids(array $value): void
    {
        $this->builder->whereIn('id', $value);
    }
}
