<?php

namespace App\GraphQL\Queries;

use App\Models\Product;

/**
 * Сводная заполненность карточек по текущей выборке товаров
 * (LASTOCHKI-JAMI-11653). Фильтры зеркалят admin_products_paginate,
 * чтобы сводка совпадала с видимым списком.
 */
final class AdminProductsFillStats
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args): array
  {
    $query = Product::query()
      ->shop()
      ->when(isset($args['shop_id']), fn ($q) => $q->where('shop_id', $args['shop_id']))
      // Сетевой каталог: список фильтруется по наличию товара в магазине
      // (available_in_shop_id вместо shop_id) — сводка обязана мерить ту же
      // выборку, иначе «средняя заполненность» считается по всей сети.
      ->when(isset($args['available_in_shop_id']),
        fn ($q) => $q->availableInShop((int) $args['available_in_shop_id']))
      ->when(!empty($args['title']), fn ($q) => $q->where(function ($qq) use ($args) {
        $qq->where('title', 'LIKE', "%{$args['title']}%")
          ->orWhere('articul', 'LIKE', "%{$args['title']}%");
      }))
      ->when(!empty($args['categories']), fn ($q) => $q->whereHas('categories',
        fn ($qq) => $qq->whereIn('categories.id', $args['categories'])))
      ->when($args['exclude_children'] ?? false, fn ($q) => $q->whereNull('parent_id'));

    return [
      'avg_percent' => round((float) ($query->avg('fill_percent') ?? 0), 1),
      'total' => $query->count(),
    ];
  }
}
