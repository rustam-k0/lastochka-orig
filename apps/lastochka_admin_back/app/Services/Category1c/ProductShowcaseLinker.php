<?php

declare(strict_types=1);

namespace App\Services\Category1c;

use App\Events\ProductUpdated;
use App\Models\Product;

/**
 * Per-product синхронизация витринных категорий товара из его привязки
 * Category1c.showcaseCategories.
 *
 * Единый источник логики связки product → витрина (`category_product`),
 * используется в трёх местах:
 *  - SyncCategoryMappingsJob — массовое применение по магазину (ручное «Применить»);
 *  - ProductEventTransformer::processRelatedData — при приёме 1С-товара из RabbitMQ;
 *  - Category1cObserver::resolvePending — когда Category1c приезжает позже своих товаров.
 *
 * Контракт:
 *  - Связывает товар ТОЛЬКО когда у его Category1c есть привязанные витрины.
 *    Пустой маппинг → no-op (НЕ затираем уже проставленные категории и НЕ сбрасываем
 *    связку в пустоту — иначе админ-товары и товары с ещё не замапленной 1С-категорией
 *    потеряли бы свои категории).
 *  - Идемпотентность: если набор витрин не изменился — ничего не делаем.
 *  - При фактическом изменении pivot диспатчим ProductUpdated (outbox → RabbitMQ →
 *    storefront), т.к. обычный save товара НЕ публикует `updated`
 *    (см. config rabbitmq-events: products.events = ['created','deleted'] + listen_events).
 */
class ProductShowcaseLinker
{
    /**
     * @param  bool  $dispatchEvent  false — связать pivot без публикации события
     *                               (когда вызывающий код сам диспатчит ProductUpdated).
     * @return bool  true — связка реально изменилась.
     */
    public function sync(Product $product, bool $dispatchEvent = true): bool
    {
        $product->loadMissing('category1c.showcaseCategories', 'categories');

        $newIds = $product->category1c
            ? $product->category1c->showcaseCategories->pluck('id')->all()
            : [];

        // Нечего привязывать (нет Category1c или у неё нет витрин) — не трогаем товар.
        if (empty($newIds)) {
            return false;
        }

        $currentIds = $product->categories->pluck('id')->all();

        $currentSorted = $currentIds;
        sort($currentSorted);
        $newSorted = $newIds;
        sort($newSorted);

        if ($currentSorted === $newSorted) {
            return false;
        }

        $payload = [];
        foreach (array_values($newIds) as $i => $id) {
            $payload[$id] = ['position' => $i];
        }
        $product->categories()->sync($payload);

        // Смена витринных категорий меняет effective-квант товара — пересчитать
        // цену кванта (LASTOCHKI-JAMI-11200 v2). dispatchEvent=false: ниже
        // публикуем ProductUpdated один раз.
        if ($product->shop_id) {
            app(\App\Services\Quantum\CategoryQuantumService::class)
                ->applyToProduct($product->fresh(), (int) $product->shop_id, null, dispatchEvent: false);
        }

        // Смена витринных категорий меняет effective-режим остатков товара
        // (наследование ignore/fixed от категорий) — материализовать effective
        // ДО финальной публикации ProductUpdated (LASTOCHKI-JAMI-11207).
        app(\App\Services\Stock\StockOverrideService::class)
            ->applyToProduct($product->fresh(), dispatchEvent: false);

        if ($dispatchEvent) {
            event(new ProductUpdated($product->fresh()));
        }

        return true;
    }
}
