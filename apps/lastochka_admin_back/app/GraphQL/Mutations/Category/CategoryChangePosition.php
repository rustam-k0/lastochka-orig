<?php

namespace App\GraphQL\Mutations\Category;

use App\Models\Category;

final class CategoryChangePosition
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    foreach ($args['items'] as $item) {
      $category = Category::find($item['id']);

      if (!$category) {
        continue;
      }

      // Eloquent save() (а не QueryBuilder update()) обязателен:
      // categories слушают стандартное Eloquent-событие `updated`
      // (config/rabbitmq-events.php → entities.categories.events),
      // через которое пакет пишет в outbox и публикует category.updated
      // с новым position в storefront. Mass-update через whereId()->update()
      // обходит observer → событие не публикуется → порядок не доезжает.
      $category->position = (int) $item['position'];
      $category->save();
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
