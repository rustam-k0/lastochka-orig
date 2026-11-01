<?php

namespace Tests\Feature;

use App\Models\AssemblyZone;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AssemblyZoneDeleteTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();
    }

    private const MUTATION = '
        mutation ($id: Int!, $table: String!) {
            EntityDelete(id: $id, table: $table) {
                code
                message
            }
        }
    ';

    private function createZone(array $attributes = []): AssemblyZone
    {
        return AssemblyZone::create(array_merge([
            'title' => 'Зона ' . uniqid(),
            'position' => 0,
        ], $attributes));
    }

    /** @test */
    public function admin_can_delete_assembly_zone()
    {
        $zone = $this->createZone();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::MUTATION, [
                'id' => $zone->id,
                'table' => 'assembly_zones',
            ]);

        // Главная регрессия LASTOCHKI-JAMI-11030: раньше падало
        // "Validation failed for the field [EntityDelete]" из-за отсутствия
        // assembly_zones в whitelist валидатора.
        $response->assertJsonMissing(['errors']);
        $response->assertJson([
            'data' => [
                'EntityDelete' => [
                    'message' => 'Запись успешно удалена',
                ],
            ],
        ]);

        $this->assertDatabaseMissing('assembly_zones', [
            'id' => $zone->id,
        ]);
    }

    /** @test */
    public function deleting_assembly_zone_nullifies_linked_category_reference()
    {
        $zone = $this->createZone();

        $category = Category::create([
            'title' => 'Категория ' . uniqid(),
            'assembly_zone_id' => $zone->id,
        ]);

        $this->assertEquals($zone->id, $category->fresh()->assembly_zone_id);

        $this->actingAs($this->adminUser)
            ->graphQL(self::MUTATION, [
                'id' => $zone->id,
                'table' => 'assembly_zones',
            ])
            ->assertJsonMissing(['errors']);

        // FK nullOnDelete: категория остаётся, ссылка на удалённую зону обнуляется.
        $this->assertNull($category->fresh()->assembly_zone_id);
    }

    /** @test */
    public function deleting_unknown_table_fails_validation()
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::MUTATION, [
                'id' => 1,
                'table' => 'orders',
            ]);

        // Не привязываемся к тексту (зависит от локали) — проверяем сам факт
        // ошибки валидации по полю table.
        $response->assertGraphQLValidationKeys(['table']);
    }
}
