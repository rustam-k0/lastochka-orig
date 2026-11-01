<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OptionalAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Пытаемся аутентифицировать пользователя через Sanctum Bearer токен
        if ($request->bearerToken()) {
            try {
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
                if ($token && $token->tokenable) {
                    // Устанавливаем пользователя через guard
                    Auth::setUser($token->tokenable);
                    $request->setUserResolver(fn() => $token->tokenable);
                }
            } catch (\Exception $e) {
                // Если токен невалидный, продолжаем без пользователя
            }
        }
        
        return $next($request);
    }
}
