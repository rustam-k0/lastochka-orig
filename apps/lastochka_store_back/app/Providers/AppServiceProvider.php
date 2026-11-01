<?php

namespace App\Providers;

use App\Models\Client;
use App\Services\ShopService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
  /**
   * Register any application services.
   *
   * @return void
   */
  public function register()
  {
    //
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
