<?php

namespace Tests\Feature;

use App\Models\KpiTarget;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class KpiTargetCrudTest extends TestCase
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

    public function test_can_create_update_and_delete_kpi_target(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->actingAs($user)->graphQL('
            mutation ($metric_key: String!, $period_start: Date!, $period_end: Date!, $target_value: Float!) {
                KpiTargetCreate(metric_key: $metric_key, period_start: $period_start, period_end: $period_end, target_value: $target_value) {
                    code message
                }
            }
        ', [
            'metric_key' => 'avg_check',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'target_value' => 1500,
        ]);
        $create->assertJson(['data' => ['KpiTargetCreate' => ['code' => 200]]]);

        $target = KpiTarget::where('metric_key', 'avg_check')->orderByDesc('id')->firstOrFail();

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $target_value: Float) {
                KpiTargetUpdate(id: $id, target_value: $target_value) { code message }
            }
        ', ['id' => $target->id, 'target_value' => 1700]);
        $update->assertJson(['data' => ['KpiTargetUpdate' => ['code' => 200]]]);
        $this->assertSame(1700.0, (float) $target->fresh()->target_value);

        $delete = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) { KpiTargetDelete(id: $id) { code message } }
        ', ['id' => $target->id]);
        $delete->assertJson(['data' => ['KpiTargetDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('kpi_targets', ['id' => $target->id]);
    }
}
