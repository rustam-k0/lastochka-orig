<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'marketplace.mode' => \App\Http\Middleware\EnsureMarketplaceMode::class,
            'address-objects-enabled' => \App\Http\Middleware\EnsureAddressObjectsEnabled::class,
            'internal.token' => \App\Http\Middleware\EnsureInternalToken::class,
            'idempotent' => \App\Http\Middleware\IdempotentRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
        // Не найденная модель (route binding, findOrFail) отдавалась клиенту текстом
        // «No query results for model [App\Models\CartItem] 9920». Мобилке нужен
        // машинный код и понятный пользователю текст без имён классов.
        $exceptions->render(\App\Exceptions\ModelNotFoundRenderer::render(...));
    })->create();
