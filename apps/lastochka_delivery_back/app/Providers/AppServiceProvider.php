<?php

namespace App\Providers;

use App\GraphQL\Validation\SafeValidationRulesProvider;
use App\Models\User;
use App\Services\ShopService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
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

    $this->app->singleton(Messaging::class, function ($app) {
      $credentials = $this->resolveFirebaseCredentials();

      if (!$credentials) {
        \Log::warning('Firebase credentials not configured — push notifications will not work');
        return null;
      }

      return (new Factory)
        ->withServiceAccount($credentials)
        ->createMessaging();
    });
  }

  /**
   * Bootstrap any application services.
   *
   * @return void
   */
  public function boot()
  {
    $user = null;

    if (\Auth::check() && (\Auth::user() instanceof User)) {
      $user = \Auth::user();
    }

    $this->app->singleton(ShopService::class, function ($app) use ($user) {
      return new ShopService($user);
    });

  }

  private function resolveFirebaseCredentials(): ?array
  {
    // 1. IntegrationConfig (courier_app → firebase)
    try {
      $config = \App\Models\IntegrationConfig::where('integration_type', 'courier_app')
        ->where('is_active', true)
        ->first();

      if ($config) {
        $driverCredentials = $config->credentials['firebase'] ?? $config->credentials;
        $json = $driverCredentials['service_account_json'] ?? null;

        if ($json) {
          $decoded = is_string($json) ? json_decode($json, true) : $json;
          if ($decoded && isset($decoded['project_id'])) {
            return $decoded;
          }
        }
      }
    } catch (\Throwable $e) {
      \Log::warning('Failed to load Firebase credentials from IntegrationConfig', [
        'error' => $e->getMessage(),
      ]);
    }

    // 2. Fallback на .env
    $envCredentials = config('firebase.credentials');

    if ($envCredentials) {
      $decoded = is_string($envCredentials) ? json_decode($envCredentials, true) : $envCredentials;
      return $decoded ?: null;
    }

    return null;
  }
}
