<?php

namespace App\Listeners;

use App\Events\ShopCategoryDetached;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Support\Facades\Log;

class PublishShopCategoryDetachedEvent
{
  public const EVENT_TYPE = 'shop_detached';

  public function handle(ShopCategoryDetached $event): void
  {
    $category = $event->category;
    $shop = $event->shop;

    $shopCode = !empty($shop->code) ? $shop->code : 'shop_' . $shop->id;

    try {
      $eventId = CustomEventPublisher::dispatch(
        'categories',
        $category,
        self::EVENT_TYPE,
        [
          'title' => $category->title,
        ],
        $shopCode
      );

      Log::info('Published shop_detached event', [
        'event_id' => $eventId,
        'category_id' => $category->id,
        'category_title' => $category->title,
        'shop_id' => $shop->id,
        'shop_code' => $shopCode,
      ]);
    } catch (\Throwable $e) {
      Log::error('Failed to publish shop_detached event', [
        'category_id' => $category->id,
        'shop_id' => $shop->id,
        'error' => $e->getMessage(),
      ]);
    }
  }
}
