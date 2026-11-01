<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Поиск в списке клиентов админки (MID-310): строка поиска ищет по ФИО, телефону и email.
 */
class ClientTitleFilterTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Client $target;
    protected Client $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();

        $this->target = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'Иван',
            'surname' => 'Петров',
            'patronymic' => 'Сергеевич',
            'email' => 'mid310-target@example.com',
            'is_authorized' => true,
        ]);

        $this->other = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'Другой',
            'email' => 'mid310-other@example.com',
            'is_authorized' => true,
        ]);
    }

    private function search(string $title): array
    {
        $query = '
            query ($title: String) {
                admin_clients_paginate(first: 50, title: $title) {
                    data { id }
                }
            }
        ';

        $json = $this->actingAs($this->user)->graphQL($query, ['title' => $title])->json();
        $this->assertArrayNotHasKey('errors', $json, 'GraphQL errors: ' . json_encode($json['errors'] ?? null, JSON_UNESCAPED_UNICODE));

        return collect($json['data']['admin_clients_paginate']['data'])->pluck('id')->all();
    }

    /** @test */
    public function search_by_full_email_finds_client(): void
    {
        $ids = $this->search('mid310-target@example.com');

        $this->assertContains($this->target->id, $ids);
        $this->assertNotContains($this->other->id, $ids);
    }

    /** @test */
    public function search_by_email_part_finds_client(): void
    {
        $ids = $this->search('mid310-target');

        $this->assertContains($this->target->id, $ids);
        $this->assertNotContains($this->other->id, $ids);
    }

    /** @test */
    public function search_by_fio_still_works(): void
    {
        $ids = $this->search('Петров');

        $this->assertContains($this->target->id, $ids);
        $this->assertNotContains($this->other->id, $ids);
    }
}
