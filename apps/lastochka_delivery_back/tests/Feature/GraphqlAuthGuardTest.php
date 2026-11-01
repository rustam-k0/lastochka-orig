<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use GraphQL\Language\AST\DirectiveNode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Безопасность /graphql-delivery: каждое поле Query/Mutation, кроме явно
 * публичных, закрыто @guard. Анонимный запрос collector/courier и справочников
 * раньше отдавал ФИО сотрудников и данные заказов без токена.
 */
class GraphqlAuthGuardTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  /** Намеренно публичные поля: логин и флаги модулей/версия для гейтинга приложений. */
  private const PUBLIC_FIELDS = [
    'Mutation.UserLogin',
    'Query.app_info',
    'Query.me', // @auth: анониму отдаёт null, данных не раскрывает
    'Query.heavy_orders_enabled',
    'Query.courier_assignment_enabled',
    'Query.collector_payment_enabled',
    'Query.checkout_without_slots_enabled',
    'Query.honest_sign_enabled',
  ];

  public function test_every_non_public_field_has_guard_directive(): void
  {
    $schema = app(SchemaBuilder::class)->schema();
    $unguarded = [];

    foreach (['Query' => $schema->getQueryType(), 'Mutation' => $schema->getMutationType()] as $name => $type) {
      foreach ($type->getFields() as $fieldName => $field) {
        $key = "$name.$fieldName";
        if (in_array($key, self::PUBLIC_FIELDS, true)) {
          continue;
        }
        $hasGuard = false;
        foreach ($field->astNode->directives as $directive) {
          /** @var DirectiveNode $directive */
          if ($directive->name->value === 'guard') {
            $hasGuard = true;
          }
        }
        if (!$hasGuard) {
          $unguarded[] = $key;
        }
      }
    }

    $this->assertSame([], $unguarded, 'Поля без @guard: ' . implode(', ', $unguarded));
  }

  public static function anonymousQueries(): array
  {
    return [
      'collector' => ['{ collector(order_id: 1) { id name surname } }'],
      'courier' => ['{ courier(order_id: 1) { id name surname } }'],
      'shift_couriers' => ['{ shift_couriers { id } }'],
      'assembly_items' => ['{ assembly_items(assembly_id: 1) { id } }'],
      'assembly_item' => ['{ assembly_item(id: 1) { id } }'],
      'explain_reasons' => ['{ explain_reasons { id } }'],
      'order_cancel_reasons' => ['{ order_cancel_reasons { id } }'],
      'product_cancel_reasons' => ['{ product_cancel_reasons { id } }'],
      'product_replace_reasons' => ['{ product_replace_reasons { id } }'],
      'product' => ['{ product(id: 1) { id } }'],
      'product_search' => ['{ product_search(order_id: 1, id: 1, barcode: "1") { id } }'],
      'order' => ['{ order(id: 1) { id } }'],
      'printers' => ['{ printers { id } }'],
    ];
  }

  /** @dataProvider anonymousQueries */
  public function test_anonymous_gets_unauthenticated(string $query): void
  {
    $response = $this->graphQL($query);

    $this->assertSame('Unauthenticated.', $response->json('errors.0.message'));
    $this->assertEmpty(array_filter((array) $response->json('data')), 'Данные не должны отдаваться анониму');
  }

  public function test_anonymous_mutation_does_not_execute(): void
  {
    // У мутаций с @validator Lighthouse валидирует аргументы до middleware @guard,
    // поэтому анониму может прийти ошибка валидации; главное, что мутация не выполняется.
    $response = $this->graphQL('mutation { DeliveryStart(delivery: true) { code } }');

    $this->assertNotEmpty($response->json('errors'));
    $this->assertNull($response->json('data.DeliveryStart'));
  }

  public function test_authenticated_staff_keeps_previous_responses(): void
  {
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'Гард ' . uniqid(),
      'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate('collector', 'api'));
    $this->actingAs($user, 'api');

    foreach (['{ collector(order_id: 999999) { id } }', '{ courier(order_id: 999999) { id } }',
              '{ explain_reasons { id } }', '{ order_cancel_reasons { id } }',
              '{ product_cancel_reasons { id } }', '{ product_replace_reasons { id } }',
              '{ assembly_items(assembly_id: 1) { id } }'] as $query) {
      $response = $this->graphQL($query);
      $this->assertNull($response->json('errors'), $query . ' -> ' . json_encode($response->json('errors')));
    }
  }
}
