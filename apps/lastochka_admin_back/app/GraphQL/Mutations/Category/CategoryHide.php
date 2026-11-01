<?php

namespace App\GraphQL\Mutations\Category;

use App\Models\Category;

final class CategoryHide
{
  /**
   * @param  null  $_
   * @param  array{}  $args
   */
  public function __invoke($_, array $args)
  {
    $category = Category::findOrFail($args['id']);

    // Eloquent update триггерит updated → outbox публикует category.updated
    // per-shop с active=false, storefront скрывает категорию из выдачи
    $category->update(['is_active' => false]);

    return [
      'code' => 200,
      'message' => 'OK',
      'id' => $category->id,
    ];
  }
}
