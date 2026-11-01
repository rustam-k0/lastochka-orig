<?php

namespace App\Providers;

use App\Events\ProductUpdated;
use App\Events\ShopCategoryDetached;
use App\Listeners\PublishShopCategoryDetachedEvent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
  /**
   * The event listener mappings for the application.
   *
   * @var array<class-string, array<int, class-string>>
   */
  protected $listen = [
    Registered::class => [
      SendEmailVerificationNotification::class,
    ],
    ProductUpdated::class => [
      \App\Listeners\RecalculateProductFillPercent::class,
    ],
    ShopCategoryDetached::class => [
      PublishShopCategoryDetachedEvent::class,
    ],
  ];

  /**
   * Register any events for your application.
   *
   * @return void
   */
  public function boot()
  {
    //
  }
}
