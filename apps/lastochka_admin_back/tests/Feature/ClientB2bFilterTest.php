<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\User;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ClientB2bFilterTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Client $b2bClient;
    protected Client $b2cClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();

        $this->b2bClient = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'B2bClient',
            'is_authorized' => true,
            'is_b2b' => true,
        ]);

        $this->b2cClient = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'B2cClient',
            'is_authorized' => true,
            'is_b2b' => false,
        ]);
    }

    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function enableB2bModule(bool $enabled): void
    {
        AdminSetting::setValue(
            ModuleConfigService::PROJECT_MODULES_KEY,
            json_encode([
                ModuleConfigService::B2B_KEY => [
                    'label' => 'B2B',
                    'enabled' => $enabled,
                    'description' => 'B2B заказы и партнёры',
                ],
            ]),
            'json'
        );

        ModuleConfigService::invalidateCache();
    }

    private function queryClients(?bool $isB2b): array
    {
        $query = '
            query ($is_b2b: Boolean) {
                admin_clients_paginate(first: 50, is_b2b: $is_b2b) {
                    data {
                        id
                        name
                        is_b2b
                    }
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($query, ['is_b2b' => $isB2b]);

        $json = $response->json();
        $this->assertArrayNotHasKey('errors', $json, 'GraphQL errors: ' . json_encode($json['errors'] ?? null, JSON_UNESCAPED_UNICODE));

        return collect($json['data']['admin_clients_paginate']['data'])
            ->pluck('id')
            ->all();
    }

    /** @test */
    public function module_enabled_filter_true_returns_only_b2b(): void
    {
        $this->enableB2bModule(true);

        $ids = $this->queryClients(true);

        $this->assertContains($this->b2bClient->id, $ids);
        $this->assertNotContains($this->b2cClient->id, $ids);
    }

    /** @test */
    public function module_enabled_filter_false_returns_only_b2c(): void
    {
        $this->enableB2bModule(true);

        $ids = $this->queryClients(false);

        $this->assertContains($this->b2cClient->id, $ids);
        $this->assertNotContains($this->b2bClient->id, $ids);
    }

    /** @test */
    public function module_enabled_filter_null_returns_both(): void
    {
        $this->enableB2bModule(true);

        $ids = $this->queryClients(null);

        $this->assertContains($this->b2bClient->id, $ids);
        $this->assertContains($this->b2cClient->id, $ids);
    }

    /** @test */
    public function module_disabled_filter_is_ignored(): void
    {
        $this->enableB2bModule(false);

        // Даже когда клиент шлёт is_b2b=true — фильтр должен быть проигнорирован,
        // потому что модуль b2b выключен.
        $ids = $this->queryClients(true);

        $this->assertContains($this->b2bClient->id, $ids);
        $this->assertContains($this->b2cClient->id, $ids);
    }
}
