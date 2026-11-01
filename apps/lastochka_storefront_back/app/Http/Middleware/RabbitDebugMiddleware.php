<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RabbitDebugMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('rabbit-debug.enabled')) {
            abort(404);
        }

        if (app()->isProduction() && ! $this->hasValidApiKey($request)) {
            abort(403, 'Access denied. API key required.');
        }

        return $next($request);
    }

    /**
     * Проверить наличие валидного API ключа.
     */
    private function hasValidApiKey(Request $request): bool
    {
        $configKey = config('rabbit-debug.api_key');

        if (empty($configKey)) {
            return false;
        }

        $providedKey = $request->header('X-Rabbit-Debug-Key')
            ?? $request->query('key');

        return $providedKey === $configKey;
    }
}
