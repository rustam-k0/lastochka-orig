<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Role;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * ELH-117: у атрибута появилось описание (контент INFO-модалки экрана
 * «Характеристики» в мобилке) и группа «Параметры упаковки» (104).
 */
class AttributeDescriptionTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;

    private const CREATE_MUTATION = '
        mutation ($title: String, $code: String, $type_id: Int, $category_type_id: Int, $description: String) {
            AttributeCreate(title: $title, code: $code, type_id: $type_id, category_type_id: $category_type_id, description: $description) {
                code
                message
            }
        }
    ';

    private const UPDATE_MUTATION = '
        mutation ($id: Int, $title: String, $code: String, $description: String) {
            AttributeUpdate(id: $id, title: $title, code: $code, description: $description) {
                code
                message
            }
        }
    ';

    private const ITEM_QUERY = '
        query ($id: Int) {
            attribute_item(id: $id) {
                id
                description
                category_type_id
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();

        $adminRole = Role::findOrCreate(Role::ADMIN);
        $adminRole->scope_type = Role::SCOPE_GLOBAL;
        $adminRole->save();

        $this->admin = User::create([
            'name' => 'AttrDesc Admin',
            'phone' => '+7905' . mt_rand(1000000, 9999999),
            'email' => uniqid('attr_desc_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($adminRole);
    }

    /** @test */
    public function attribute_create_persists_description_and_packaging_group(): void
    {
        $code = 'pack_length_' . mt_rand(10000, 99999);

        $response = $this->actingAs($this->admin)->graphQL(self::CREATE_MUTATION, [
            'title' => 'Длина упаковки',
            'code' => $code,
            'type_id' => Type::ATTRIBUTE_STRING,
            'category_type_id' => Type::ATTRIBUTE_PACKAGING,
            'description' => 'Длина товара в заводской упаковке, мм',
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));

        $attribute = Attribute::where('code', $code)->firstOrFail();
        $this->assertSame('Длина товара в заводской упаковке, мм', $attribute->description);
        $this->assertSame(Type::ATTRIBUTE_PACKAGING, (int) $attribute->category_type_id);
    }

    /** @test */
    public function attribute_update_changes_description_and_query_returns_it(): void
    {
        $code = 'nominal_voltage_' . mt_rand(10000, 99999);

        $attribute = Attribute::create([
            'title' => 'Номинальное напряжение',
            'code' => $code,
            'type_id' => Type::ATTRIBUTE_STRING,
            'category_type_id' => Type::ATTRIBUTE_OTHER,
        ]);

        $update = $this->actingAs($this->admin)->graphQL(self::UPDATE_MUTATION, [
            'id' => $attribute->id,
            'title' => 'Номинальное напряжение',
            'code' => $code,
            'description' => 'Заданное значение напряжения, при котором аппарат работает в нормальном режиме',
        ]);

        $this->assertNull($update->json('errors'), json_encode($update->json('errors'), JSON_UNESCAPED_UNICODE));

        $query = $this->actingAs($this->admin)->graphQL(self::ITEM_QUERY, ['id' => $attribute->id]);

        $this->assertNull($query->json('errors'), json_encode($query->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(
            'Заданное значение напряжения, при котором аппарат работает в нормальном режиме',
            $query->json('data.attribute_item.description')
        );
    }
}
