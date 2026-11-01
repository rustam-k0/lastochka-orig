<?php

namespace App\GraphQL\Mutations\Product;

use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\Status;

final class ProductHide
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args)
    {
      $product = Product::findOrFail($args['id']);
      $oldStatusId = $product->status_id;

      $product->update([
        'status_id' => Status::CODE_NOT_PUBLISHED,
        'publish_after_unlock' => 0,
      ]);

      if ($oldStatusId !== Status::CODE_NOT_PUBLISHED) {
        $user = auth()->user();

        ProductHistory::log(
          $product->id,
          ProductHistory::ACTION_STATUS_CHANGED,
          ProductHistory::ACTOR_STAFF,
          $user?->id,
          $user?->name,
          (string) $oldStatusId,
          (string) Status::CODE_NOT_PUBLISHED,
          'Деактивирован вручную через админку'
        );
      }

      event(new \App\Events\ProductUpdated($product));

      return [
        'code' => 200,
        'message' => 'OK'
      ];
    }
}
