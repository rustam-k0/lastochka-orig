<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Comparison;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Гарантирует, что добавление модуля Comparisons не сломало существующие endpoints
 * и что comparisons-роуты прикрыты auth:sanctum + throttle.
 */
final class ComparisonZeroChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_storefront_routes_intact(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->all();

        foreach (['api/v1/stories', 'api/v1/stores', 'api/v1/cart', 'api/v1/orders'] as $uri) {
            $this->assertContains($uri, $names, "URI {$uri} пропал из routes/api.php");
        }
    }

    public function test_comparison_routes_protected_by_sanctum_and_throttle(): void
    {
        $compRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'comparisons'));

        $this->assertNotEmpty($compRoutes, 'Comparisons routes должны быть зарегистрированы.');

        foreach ($compRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware,
                "Route {$route->uri()} должен быть прикрыт auth:sanctum");

            $hasThrottle = collect($middleware)
                ->contains(fn (string $m): bool => str_starts_with($m, 'throttle:'));
            $this->assertTrue($hasThrottle,
                "Route {$route->uri()} должен иметь throttle middleware");
        }
    }
}
