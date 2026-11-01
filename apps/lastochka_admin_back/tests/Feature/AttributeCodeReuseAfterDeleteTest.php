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
 * ALADDIN-11991: код удалённого атрибута должен освобождаться —
 * повторное создание атрибута с тем же системным кодом не должно
 * отбиваться валидацией («Атрибут с таким системным кодом уже существует»)
 * и не должно падать на UNIQUE-индексе attributes.code.
 */
class AttributeCodeReuseAfterDeleteTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;

    private const CREATE_MUTATION = '
        mutation ($title: String, $code: String, $type_id: Int, $category_type_id: Int) {
            AttributeCreate(title: $title, code: $code, type_id: $type_id, category_type_id: $category_type_id) {
                code
                message
            }
        }
    ';

    private const DELETE_MUTATION = '
        mutation ($id: Int!, $table: String!) {
            EntityDelete(id: $id, table: $table) {
                code
                message
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
            'name' => 'AttributeCode Admin',
            'phone' => '+7905' . mt_rand(1000000, 9999999),
            'email' => uniqid('attr_admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($adminRole);
    }

    private function createViaMutation(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->graphQL(self::CREATE_MUTATION, [
            'title' => 'Производитель',
            'code' => $code,
            'type_id' => Type::ATTRIBUTE_STRING,
            'category_type_id' => Type::ATTRIBUTE_OTHER,
        ]);
    }

    /** @test */
    public function attribute_can_be_recreated_with_code_of_deleted_attribute(): void
    {
        $code = 'proizvoditel_' . mt_rand(10000, 99999);

        $first = $this->createViaMutation($code);
        $this->assertNull($first->json('errors'), 'Первое создание должно пройти без ошибок');

        $attribute = Attribute::where('code', $code)->firstOrFail();

        $delete = $this->actingAs($this->admin)->graphQL(self::DELETE_MUTATION, [
            'id' => $attribute->id,
            'table' => 'attributes',
        ]);
        $this->assertNull($delete->json('errors'), 'Удаление должно пройти без ошибок');
        $this->assertSoftDeleted('attributes', ['id' => $attribute->id]);

        // Сценарий тикета: создаём атрибут с тем же кодом заново
        $second = $this->createViaMutation($code);

        $this->assertNull(
            $second->json('errors'),
            'Повторное создание с кодом удалённого атрибута отбито: '
                . json_encode($second->json('errors.0.extensions.validation'), JSON_UNESCAPED_UNICODE)
        );

        $recreated = Attribute::where('code', $code)->first();
        $this->assertNotNull($recreated, 'Новый активный атрибут с этим кодом должен существовать');
        $this->assertNotSame($attribute->id, $recreated->id);
    }

    /** @test */
    public function deleting_attribute_frees_its_code(): void
    {
        $code = 'test_free_code_' . mt_rand(10000, 99999);

        $attribute = Attribute::create([
            'title' => 'Тестовый атрибут',
            'code' => $code,
        ]);

        $attribute->delete();

        $trashed = Attribute::withTrashed()->findOrFail($attribute->id);

        $this->assertNotSame($code, $trashed->code, 'Код удалённого атрибута должен быть переименован');
        $this->assertStringStartsWith($code, $trashed->code);
        $this->assertNull(Attribute::withTrashed()->where('code', $code)->first(), 'Исходный код свободен');
    }

    /** @test */
    public function backfill_migration_frees_codes_of_already_deleted_attributes_idempotently(): void
    {
        $code = 'legacy_deleted_' . mt_rand(10000, 99999);

        // Строка «как до фикса»: soft-deleted с чистым кодом, минуя Eloquent-хук
        $id = \DB::table('attributes')->insertGetId([
            'title' => 'Legacy',
            'code' => $code,
            'type_id' => Type::ATTRIBUTE_SELECT_ONE,
            'category_type_id' => Type::ATTRIBUTE_OTHER,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_13_120000_free_codes_of_soft_deleted_attributes.php');

        $migration->up();
        $mangled = \DB::table('attributes')->where('id', $id)->value('code');
        $this->assertNotSame($code, $mangled);
        $this->assertStringStartsWith($code, $mangled);

        // Идемпотентность: повторный прогон ничего не меняет
        $migration->up();
        $this->assertSame($mangled, \DB::table('attributes')->where('id', $id)->value('code'));
    }
}
