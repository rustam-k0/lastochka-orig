<?php

namespace App\Providers;

use App\GraphQL\Validation\SafeValidationRulesProvider;
use App\Models\Client;
use App\Services\ShopService;
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
    $user = null;

    if (\Auth::check() && (\Auth::user() instanceof Client)) {
      $user = \Auth::user();
    }

    $this->app->singleton(ShopService::class, function ($app) use ($user) {
      return new ShopService($user);
    });
  }
}
