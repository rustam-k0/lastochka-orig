<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShopAlertRule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopAlertRuleCrudTest extends TestCase
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

    public function test_can_create_update_and_delete_alert_rule(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->actingAs($user)->graphQL('
            mutation ($name: String!, $metric_key: String!, $comparison: String!, $threshold_value: Float!, $recommended_action_text: String) {
                ShopAlertRuleCreate(name: $name, metric_key: $metric_key, comparison: $comparison, threshold_value: $threshold_value, recommended_action_text: $recommended_action_text) {
                    code message
                }
            }
        ', [
            'name' => 'Падение среднего чека',
            'metric_key' => 'avg_check',
            'comparison' => 'lt',
            'threshold_value' => 1000,
            'recommended_action_text' => 'Проверить ассортимент и промо в магазине',
        ]);
        $create->assertJson(['data' => ['ShopAlertRuleCreate' => ['code' => 200]]]);

        $rule = ShopAlertRule::where('metric_key', 'avg_check')->orderByDesc('id')->firstOrFail();

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $threshold_value: Float) {
                ShopAlertRuleUpdate(id: $id, threshold_value: $threshold_value) { code message }
            }
        ', ['id' => $rule->id, 'threshold_value' => 1200]);
        $update->assertJson(['data' => ['ShopAlertRuleUpdate' => ['code' => 200]]]);
        $this->assertSame(1200.0, (float) $rule->fresh()->threshold_value);

        $delete = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) { ShopAlertRuleDelete(id: $id) { code message } }
        ', ['id' => $rule->id]);
        $delete->assertJson(['data' => ['ShopAlertRuleDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('shop_alert_rules', ['id' => $rule->id]);
    }
}
