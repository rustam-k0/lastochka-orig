<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Статик-токен для внутренних межсервисных вызовов (delivery_back → storefront).
 * Токен в заголовке X-Internal-Token, сверка constant-time. Пустой
 * сконфигурированный токен = endpoint закрыт (fail closed).
 */
final class EnsureInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.internal_api.token', '');
        $provided = (string) $request->header('X-Internal-Token', '');

        if ($configured === '' || ! hash_equals($configured, $provided)) {
            abort(403);
        }

        return $next($request);
    }
}
