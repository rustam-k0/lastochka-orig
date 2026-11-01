<?php

namespace App\Services\MerchantMultiShop;

use App\Jobs\MerchantMultiShop\CreateMerchantEntityCopiesJob;
use App\Models\Shop;

/**
 * Создание merchant-сущностей (акции, баннеры, товарные группы) сразу в
 * нескольких магазинах (MID-237/238/229).
 *
 * Контракт аргументов мутаций: ровно один источник целей —
 *  - shop_id   — легаси: одна запись в одном магазине;
 *  - shop_ids  — явный список магазинов (чекбоксы в форме);
 *  - all_shops — все живые магазины сети.
 *
 * Первая (primary) запись создаётся синхронно в мутации и возвращается
 * клиенту; копии в остальные магазины создаёт фоновая джоба
 * (CreateMerchantEntityCopiesJob) — при all_shops магазинов может быть
 * ~1.5k, синхронный fan-out в HTTP-запросе ловил бы 504 (см. грабли
 * раскатки категорий 1С от 2026-08-10).
 */
final class MultiShopCreateService
{
    /** Максимум магазинов в явном списке shop_ids — дальше только all_shops. */
    public const MAX_EXPLICIT_SHOPS = 100;

    /**
     * Резолв целевых магазинов из аргументов мутации.
     *
     * @return array{primary: int, rest: int[], all_shops: bool}
     */
    public static function resolveTargets(array $args): array
    {
        if (!empty($args['all_shops'])) {
            return [
                'primary' => self::anchorShopId(),
                'rest' => [],
                'all_shops' => true,
            ];
        }

        $shopIds = array_values(array_unique(array_map('intval', $args['shop_ids'] ?? [])));

        if ($shopIds !== []) {
            $primary = array_shift($shopIds);

            return [
                'primary' => $primary,
                'rest' => $shopIds,
                'all_shops' => false,
            ];
        }

        return [
            'primary' => (int) ($args['shop_id'] ?? 0),
            'rest' => [],
            'all_shops' => false,
        ];
    }

    /**
     * Диспатч фоновой джобы копий, если целей больше одной.
     *
     * @param string $entity см. CreateMerchantEntityCopiesJob::ENTITY_*
     * @param array $args санитизированные аргументы создания
     * @param array{primary: int, rest: int[], all_shops: bool} $targets
     */
    public static function dispatchCopies(string $entity, array $args, array $targets): void
    {
        if (!$targets['all_shops'] && $targets['rest'] === []) {
            return;
        }

        // Пути/идентификаторы уже загруженных изображений сериализуемы,
        // сами args прошли санитизацию в мутации.
        unset($args['shop_id'], $args['shop_ids'], $args['all_shops']);

        CreateMerchantEntityCopiesJob::dispatch(
            $entity,
            $args,
            $targets['rest'],
            $targets['all_shops'],
            $targets['primary'],
        );
    }

    /**
     * Якорный магазин сети: shops.default = 1 (семантика network_catalog),
     * иначе минимальный id живого магазина.
     */
    public static function anchorShopId(): int
    {
        $default = Shop::query()->where('default', 1)->value('id');

        return (int) ($default ?? Shop::query()->min('id'));
    }

    /**
     * Все живые магазины, кроме уже созданного primary.
     *
     * @return int[]
     */
    public static function allTargetShopIds(int $excludeShopId): array
    {
        return Shop::query()
            ->where('id', '!=', $excludeShopId)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
