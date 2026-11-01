<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Models\UserShop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * ALADDIN-12067: тексты валидации графика работы должны быть человекочитаемыми
 * на русском, без сырых имён полей («Поле opening time обязательно для
 * заполнения, когда type id равно 1.»).
 */
class WorkingHoursValidationMessagesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;
    protected Shop $shop;

    private const SHOP_MUTATION = '
        mutation ($shop_id: Int, $type_id: Int, $opening_time: String, $closing_time: String, $days: [Int]) {
            SetShopWorkingHours(
                shop_id: $shop_id
                type_id: $type_id
                opening_time: $opening_time
                closing_time: $closing_time
                days: $days
            ) {
                code
                message
            }
        }
    ';

    private const USER_MUTATION = '
        mutation ($user_shop_id: Int, $opening_time: String, $closing_time: String, $days: [Int]) {
            SetUserWorkingHours(
                user_shop_id: $user_shop_id
                opening_time: $opening_time
                closing_time: $closing_time
                days: $days
            ) {
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
            'name' => 'WorkingHours Admin',
            'phone' => '+7904' . mt_rand(1000000, 9999999),
            'email' => uniqid('wh_admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($adminRole);

        $this->shop = Shop::create([
            'title' => 'WorkingHours shop ' . uniqid(),
            'status_id' => Status::CODE_ACTIVE,
        ]);
    }

    private function validation($response): array
    {
        $response->assertJsonPath('errors.0.extensions.category', 'validation');

        return $response->json('errors.0.extensions.validation') ?? [];
    }

    /** @test */
    public function shop_schedule_without_times_returns_human_readable_messages(): void
    {
        $response = $this->actingAs($this->admin)->graphQL(self::SHOP_MUTATION, [
            'shop_id' => $this->shop->id,
            'type_id' => Type::WORK_SCHEDULE,
            'opening_time' => null,
            'closing_time' => null,
            'days' => [Type::MONDAY],
        ]);

        $validation = $this->validation($response);

        $this->assertSame('Укажите время открытия', $validation['opening_time'][0] ?? null);
        $this->assertSame('Укажите время закрытия', $validation['closing_time'][0] ?? null);
    }

    /** @test */
    public function shop_schedule_with_inverted_times_returns_human_readable_messages(): void
    {
        $response = $this->actingAs($this->admin)->graphQL(self::SHOP_MUTATION, [
            'shop_id' => $this->shop->id,
            'type_id' => Type::WORK_SCHEDULE,
            'opening_time' => '22:00',
            'closing_time' => '09:00',
            'days' => [Type::MONDAY],
        ]);

        $validation = $this->validation($response);

        $this->assertSame(
            'Время открытия должно быть раньше времени закрытия',
            $validation['opening_time'][0] ?? null
        );
        $this->assertSame(
            'Время закрытия должно быть позже времени открытия',
            $validation['closing_time'][0] ?? null
        );
    }

    /** @test */
    public function shop_schedule_messages_do_not_leak_raw_field_names(): void
    {
        $response = $this->actingAs($this->admin)->graphQL(self::SHOP_MUTATION, [
            'shop_id' => $this->shop->id,
            'type_id' => Type::WORK_SCHEDULE,
            'opening_time' => null,
            'closing_time' => null,
            'days' => [Type::MONDAY],
        ]);

        $allMessages = implode(' ', array_merge(...array_values($this->validation($response))));

        $this->assertStringNotContainsString('opening time', $allMessages);
        $this->assertStringNotContainsString('closing time', $allMessages);
        $this->assertStringNotContainsString('type id', $allMessages);
    }

    /** @test */
    public function user_schedule_without_times_returns_human_readable_messages(): void
    {
        $userShop = UserShop::create([
            'user_id' => $this->admin->id,
            'shop_id' => $this->shop->id,
        ]);

        $response = $this->actingAs($this->admin)->graphQL(self::USER_MUTATION, [
            'user_shop_id' => $userShop->id,
            'opening_time' => null,
            'closing_time' => null,
            'days' => [Type::MONDAY],
        ]);

        $validation = $this->validation($response);

        $this->assertSame('Укажите время начала работы', $validation['opening_time'][0] ?? null);
        $this->assertSame('Укажите время окончания работы', $validation['closing_time'][0] ?? null);
    }
}
