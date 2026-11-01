<?php

namespace Tests\Feature;

use App\Models\OperatingCost;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class OperatingCostCrudTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function makeAdminUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole(Role::findOrCreate(Role::ADMIN));

        return $user;
    }

    public function test_can_create_update_and_delete_operating_cost(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->actingAs($user)->graphQL('
            mutation ($category: String!, $rule_type: String!, $value: Float!, $period_start: Date!) {
                OperatingCostCreate(category: $category, rule_type: $rule_type, value: $value, period_start: $period_start) {
                    code message
                }
            }
        ', [
            'category' => 'rent',
            'rule_type' => 'fixed_amount',
            'value' => 50000,
            'period_start' => '2026-07-01',
        ]);
        $create->assertJson(['data' => ['OperatingCostCreate' => ['code' => 200]]]);

        $cost = OperatingCost::where('category', 'rent')->orderByDesc('id')->firstOrFail();

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $rule_type: String, $value: Float) {
                OperatingCostUpdate(id: $id, rule_type: $rule_type, value: $value) { code message }
            }
        ', ['id' => $cost->id, 'rule_type' => 'pct_of_revenue', 'value' => 2.5]);
        $update->assertJson(['data' => ['OperatingCostUpdate' => ['code' => 200]]]);
        $this->assertSame('pct_of_revenue', $cost->fresh()->rule_type);

        $delete = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) { OperatingCostDelete(id: $id) { code message } }
        ', ['id' => $cost->id]);
        $delete->assertJson(['data' => ['OperatingCostDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('store_operating_costs', ['id' => $cost->id]);
    }

    public function test_rejects_unknown_rule_type(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation ($category: String!, $rule_type: String!, $value: Float!, $period_start: Date!) {
                OperatingCostCreate(category: $category, rule_type: $rule_type, value: $value, period_start: $period_start) {
                    code message
                }
            }
        ', [
            'category' => 'rent',
            'rule_type' => 'invalid_type',
            'value' => 100,
            'period_start' => '2026-07-01',
        ]);

        $response->assertGraphQLErrorMessage('Validation failed for the field [OperatingCostCreate].');
    }

    public function test_rejects_pct_of_revenue_value_above_100(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation ($category: String!, $rule_type: String!, $value: Float!, $period_start: Date!) {
                OperatingCostCreate(category: $category, rule_type: $rule_type, value: $value, period_start: $period_start) {
                    code message
                }
            }
        ', [
            'category' => 'rent',
            'rule_type' => 'pct_of_revenue',
            'value' => 150,
            'period_start' => '2026-07-01',
        ]);

        $response->assertGraphQLErrorMessage('Validation failed for the field [OperatingCostCreate].');
    }

    public function test_accepts_pct_of_revenue_value_within_100(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation ($category: String!, $rule_type: String!, $value: Float!, $period_start: Date!) {
                OperatingCostCreate(category: $category, rule_type: $rule_type, value: $value, period_start: $period_start) {
                    code message
                }
            }
        ', [
            'category' => 'rent',
            'rule_type' => 'pct_of_revenue',
            'value' => 2.5,
            'period_start' => '2026-07-01',
        ]);

        $response->assertJson(['data' => ['OperatingCostCreate' => ['code' => 200]]]);
    }
}
