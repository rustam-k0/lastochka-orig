<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RabbitDebugAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_404_when_disabled(): void
    {
        config(['rabbit-debug.enabled' => false]);

        $this->getJson('/api/debug/rabbit/messages')->assertNotFound();
        $this->getJson('/api/debug/rabbit/stats')->assertNotFound();
        $this->get('/debug/rabbit')->assertNotFound();
    }

    public function test_accessible_when_enabled_in_non_production(): void
    {
        config(['rabbit-debug.enabled' => true]);

        $this->getJson('/api/debug/rabbit/messages')->assertOk();
        $this->getJson('/api/debug/rabbit/stats')->assertOk();
        $this->get('/debug/rabbit')->assertOk();
    }

    public function test_requires_api_key_in_production(): void
    {
        config(['rabbit-debug.enabled' => true]);
        config(['rabbit-debug.api_key' => 'test-secret-key']);

        // Simulate production
        $this->app->detectEnvironment(fn () => 'production');

        // Without key - forbidden
        $this->getJson('/api/debug/rabbit/messages')->assertForbidden();

        // With wrong key - forbidden
        $this->getJson('/api/debug/rabbit/messages?key=wrong-key')->assertForbidden();

        // With correct key via query param - ok
        $this->getJson('/api/debug/rabbit/messages?key=test-secret-key')->assertOk();

        // With correct key via header - ok
        $this->getJson('/api/debug/rabbit/messages', [
            'X-Rabbit-Debug-Key' => 'test-secret-key',
        ])->assertOk();
    }

    public function test_forbidden_in_production_without_api_key_configured(): void
    {
        config(['rabbit-debug.enabled' => true]);
        config(['rabbit-debug.api_key' => null]);

        $this->app->detectEnvironment(fn () => 'production');

        $this->getJson('/api/debug/rabbit/messages')->assertForbidden();
    }

    public function test_web_route_accessible_with_api_key(): void
    {
        config(['rabbit-debug.enabled' => true]);
        config(['rabbit-debug.api_key' => 'web-secret-key']);

        $this->app->detectEnvironment(fn () => 'production');

        // Without key - forbidden
        $this->get('/debug/rabbit')->assertForbidden();

        // With correct key - ok
        $this->get('/debug/rabbit?key=web-secret-key')->assertOk();
    }
}
