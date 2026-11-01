<?php

namespace App\GraphQL\Mutations\Category;

use App\Models\Category;

final class CategoryPublish
{
  /**
   * @param  null  $_
   * @param  array{}  $args
   */
  public function __invoke($_, array $args)
  {
    $category = Category::findOrFail($args['id']);

    $category->update(['is_active' => true]);

    return [
      'code' => 200,
      'message' => 'OK',
      'id' => $category->id,
    ];
  }
}
