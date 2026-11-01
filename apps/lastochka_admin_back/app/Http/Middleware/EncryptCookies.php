<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
  /**
   * The names of the cookies that should not be encrypted.
   *
   * @var array<int, string>
   */
  protected $except = [
    // Sanctum-токен админки: admin_front читает куку через js-cookie в открытом
    // виде, а DevLoginController выставляет её сервером (dev-вход по ссылке)
    'admin_token',
  ];
}
