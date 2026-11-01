<?php

namespace App\Providers;

use App\Models\EmailConfirm;
use App\Models\Role;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Str;

class AuthServiceProvider extends ServiceProvider
{
  /**
   * The policy mappings for the application.
   *
   * @var array<class-string, class-string>
   */
  protected $policies = [
    // 'App\Models\Model' => 'App\Policies\ModelPolicy',
  ];

  /**
   * Register any authentication / authorization services.
   *
   * @return void
   */
  public function boot()
  {
    $this->registerPolicies();

    /**
     * Ссылка на подтверждение почты
     */
    VerifyEmail::createUrlUsing(function ($user) {
      $token = Str::random(40);

      EmailConfirm::create([
        'user_id' => $user->id,
        'email' => $user->email,
        'token' => $token,
      ]);

      return config('app.url') . "?register_confirm_token=$token";
    });
  }


}
