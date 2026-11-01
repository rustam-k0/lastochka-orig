<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BasicAuthFromConfig
{
    public function handle(Request $request, Closure $next)
    {
        $config = app('current_shop_config');

        if (!$config) {
            abort(401, 'Unauthorized');
        }

        $credentials = $config->credentials;
        $expectedLogin = $credentials['login'] ?? null;
        $expectedPassword = $credentials['password'] ?? null;

        if (!$expectedLogin || !$expectedPassword) {
            Log::channel('1c-exchange')->warning('Shop config has no credentials', [
                'shop_code' => app('current_shop_code'),
            ]);
            abort(401, 'Unauthorized');
        }

        $requestLogin = $request->getUser();
        $requestPassword = $request->getPassword();

        if ($requestLogin !== $expectedLogin || $requestPassword !== $expectedPassword) {
            Log::channel('1c-exchange')->warning('Basic Auth failed', [
                'shop_code' => app('current_shop_code'),
                'provided_login' => $requestLogin,
            ]);
            abort(401, 'Unauthorized');
        }

        // Let mavsan's auth pass by logging in a user (if users table exists)
        try {
            $user = \App\Models\User::first();
            if ($user) {
                Auth::login($user);
            }
        } catch (\Throwable $e) {
            // users table may not exist — not critical
        }

        return $next($request);
    }
}
