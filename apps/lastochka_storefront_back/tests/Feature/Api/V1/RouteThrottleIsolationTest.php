<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Счётчики throttle у разных роутов не должны пересекаться.
 *
 * 'throttle:5,1' без префикса считает по ключу «пользователь» (или «домен|IP» для анонима),
 * общему для всех роутов: пять запросов карты лояльности за минуту блокировали создание заказа.
 */
final class RouteThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_throttle_has_own_prefix(): void
    {
        $prefixes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $params = explode(',', substr($middleware, strlen('throttle:')));
                $this->assertCount(3, $params,
                    "Route {$route->uri()}: throttle без префикса ({$middleware}) делит счётчик с другими роутами");

                $prefixes[$params[2]][] = $middleware;
            }
        }

        // Один префикс допустим у группы роутов (comparisons, bonuses), но только с одинаковым лимитом.
        foreach ($prefixes as $prefix => $middlewares) {
            $this->assertCount(1, array_unique($middlewares),
                "Префикс {$prefix} используется с разными лимитами: ".implode(', ', array_unique($middlewares)));
        }
    }

    public function test_loyalty_card_requests_do_not_exhaust_order_create_limit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/loyalty-card')->assertOk();
        }

        // Пустое тело: важно лишь то, что запрос дошёл до валидации, а не упёрся в 429.
        $this->postJson('/api/v1/orders', [])->assertUnprocessable();
    }

    public function test_order_create_limit_still_applies(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/orders', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/orders', [])->assertTooManyRequests();
    }

    public function test_anonymous_search_does_not_exhaust_feedback_limit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/search/suggestions?query=пиво');
        }

        $this->postJson('/api/v1/feedback', [])->assertUnprocessable();
    }
}
