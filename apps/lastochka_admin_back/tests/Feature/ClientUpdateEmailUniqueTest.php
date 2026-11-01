<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ClientUpdateEmailUniqueTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    /** @test */
    public function email_can_be_reused_from_unauthorized_zombie_duplicate(): void
    {
        $email = 'dedup-' . mt_rand(1000, 9999) . '@example.com';

        // зомби-дубль (не авторизован)
        Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'email' => $email,
            'is_authorized' => false,
        ]);

        $victim = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'Victim',
            'is_authorized' => true,
        ]);

        $response = $this->actingAs($this->user)->graphQL(
            'mutation($id: Int!, $email: String) { ClientUpdate(id: $id, email: $email) { code message } }',
            ['id' => $victim->id, 'email' => $email]
        );

        $response->assertJson(['data' => ['ClientUpdate' => ['code' => 200]]]);
        $victim->refresh();
        $this->assertSame($email, $victim->email);
    }

    /** @test */
    public function email_cannot_be_reused_from_active_authorized_client(): void
    {
        $email = 'taken-' . mt_rand(1000, 9999) . '@example.com';

        Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'email' => $email,
            'is_authorized' => true,
        ]);

        $victim = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'Victim',
            'is_authorized' => true,
        ]);

        $response = $this->actingAs($this->user)->graphQL(
            'mutation($id: Int!, $email: String) { ClientUpdate(id: $id, email: $email) { code message } }',
            ['id' => $victim->id, 'email' => $email]
        );

        $response->assertJsonStructure(['errors']);
        $victim->refresh();
        $this->assertNull($victim->email);
    }
}
