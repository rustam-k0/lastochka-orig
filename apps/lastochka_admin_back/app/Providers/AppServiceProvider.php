<?php

namespace App\Providers;

use App\GraphQL\Validation\SafeValidationRulesProvider;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Observers\Category1cObserver;
use App\Observers\CategoryObserver;
use App\Observers\OrderDeliveryObserver;
use App\Observers\ProductObserver;
use App\Observers\ShopObserver;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Support\Contracts\ProvidesValidationRules;

class AppServiceProvider extends ServiceProvider
{
  /**
   * Register any application services.
   *
   * @return void
   */
  public function register()
  {
    $this->app->bind(ProvidesValidationRules::class, SafeValidationRulesProvider::class);
  }

  /**
   * Bootstrap any application services.
   *
   * @return void
   */
  public function boot()
  {
    Category::observe(CategoryObserver::class);
    Category1c::observe(Category1cObserver::class);
    Order::observe(OrderDeliveryObserver::class);
    Product::observe(ProductObserver::class);
    Shop::observe(ShopObserver::class);

    \App\Services\Category1c\NetworkScopeConfigurator::apply();
  }
}
