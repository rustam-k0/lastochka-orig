<?php

namespace App\GraphQL\Mutations\Review;

use App\Models\Review;

final class ReviewChangeStatus
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    Review::whereId($args['id'])->update([
      'status_id' => $args['status_id']
    ]);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
