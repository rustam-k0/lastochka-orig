<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class FaqChangePositionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    private User $user;

    private const MUTATION = '
        mutation Reorder($items: [FaqPositionInput!]!) {
            FaqChangePosition(items: $items) {
                code
                message
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        // The rabbitmq-events package uses a static flag $observersRegistered that
        // prevents re-registering Eloquent observers between tests. Reset it so the
        // outbox observer is attached to the current app instance.
        $reflection = new \ReflectionClass(\Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class);
        if ($reflection->hasProperty('observersRegistered')) {
            $prop = $reflection->getProperty('observersRegistered');
            $prop->setAccessible(true);
            $prop->setValue(null, false);
        }

        $provider = new \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider($this->app);
        $provider->boot();

        $this->user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $this->user->assignRole(Role::findOrCreate(Role::ADMIN));
    }

    private function createFaq(?int $position): Faq
    {
        return Faq::create([
            'question' => 'Вопрос ' . uniqid(),
            'answer' => 'Ответ',
            'is_active' => true,
            'position' => $position,
        ]);
    }

    public function test_updates_positions_for_multiple_faqs_in_one_call(): void
    {
        $f1 = $this->createFaq(1);
        $f2 = $this->createFaq(2);
        $f3 = $this->createFaq(null);

        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'items' => [
                ['id' => $f1->id, 'position' => 3],
                ['id' => $f2->id, 'position' => 1],
                ['id' => $f3->id, 'position' => 2],
            ],
        ]);

        $response->assertJsonPath('data.FaqChangePosition.code', 200);

        $this->assertSame(3, $f1->fresh()->position);
        $this->assertSame(1, $f2->fresh()->position);
        $this->assertSame(2, $f3->fresh()->position);
    }

    public function test_publishes_outbox_event_only_for_changed_faqs(): void
    {
        $changed = $this->createFaq(1);
        $unchanged = $this->createFaq(2);

        $before = DB::table('event_outbox')->where('routing_key', 'faq.updated')->count();

        $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'items' => [
                ['id' => $changed->id, 'position' => 5],
                ['id' => $unchanged->id, 'position' => 2],
            ],
        ]);

        $after = DB::table('event_outbox')->where('routing_key', 'faq.updated')->count();

        $this->assertSame(
            $before + 1,
            $after,
            'Ровно одно faq.updated: запись без фактического сдвига не должна публиковать событие'
        );
        $this->assertSame(5, $changed->fresh()->position);
        $this->assertSame(2, $unchanged->fresh()->position);
    }

    public function test_validates_items_must_not_be_empty(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, ['items' => []]);

        $response->assertJsonStructure(['errors']);
    }

    public function test_validates_id_must_exist(): void
    {
        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'items' => [['id' => 999999999, 'position' => 1]],
        ]);

        $response->assertJsonStructure(['errors']);
    }

    public function test_rejects_soft_deleted_faq_id(): void
    {
        $faq = $this->createFaq(1);
        $faq->delete();

        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'items' => [['id' => $faq->id, 'position' => 1]],
        ]);

        $response->assertJsonStructure(['errors']);
    }

    public function test_rejects_duplicate_ids_in_items(): void
    {
        $faq = $this->createFaq(1);

        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'items' => [
                ['id' => $faq->id, 'position' => 1],
                ['id' => $faq->id, 'position' => 2],
            ],
        ]);

        $response->assertJsonStructure(['errors']);
    }

    public function test_requires_authentication(): void
    {
        $faq = $this->createFaq(1);

        $response = $this->graphQL(self::MUTATION, [
            'items' => [['id' => $faq->id, 'position' => 2]],
        ]);

        $response->assertJsonStructure(['errors']);
        $this->assertSame(1, $faq->fresh()->position);
    }
}
