<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CourierAssignmentModuleTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** @test */
    public function flag_query_returns_false_by_default()
    {
        \DB::table('admin_settings')->where('key', 'project_modules')->delete();

        $this->graphQL('{ courier_assignment_enabled }')
            ->assertJson(['data' => ['courier_assignment_enabled' => false]]);
    }

    /** @test */
    public function flag_query_returns_true_when_module_enabled()
    {
        $this->setModule(true);

        $this->graphQL('{ courier_assignment_enabled }')
            ->assertJson(['data' => ['courier_assignment_enabled' => true]]);
    }

    /** @test */
    public function flag_query_returns_false_when_module_disabled()
    {
        $this->setModule(false);

        $this->graphQL('{ courier_assignment_enabled }')
            ->assertJson(['data' => ['courier_assignment_enabled' => false]]);
    }
}
