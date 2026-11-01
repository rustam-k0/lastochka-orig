<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Неизвестный аргумент со списочным значением не должен ронять эндпоинт с 500.
 *
 * graphql-php v14 в ValuesOfCorrectType для NodeKind::LST зовёт Type::getNullableType()
 * с родительским input-типом, которого у неизвестного аргумента нет — сигнатура метода
 * не допускает null, поэтому вместо ошибки валидации вылетает TypeError.
 */
class GraphqlUnknownArgumentValidationTest extends TestCase
{
  private const ENDPOINT = '/graphql-admin';

  public function test_unknown_argument_with_list_value_returns_graphql_error(): void
  {
    $response = $this->postJson(self::ENDPOINT, [
      'query' => '{ __typename(nosuchargument: [0]) }',
    ]);

    $response->assertStatus(200);

    $errors = $response->json('errors');
    $this->assertIsArray($errors);
    $this->assertStringContainsString('Unknown argument', $errors[0]['message']);
  }

  public function test_unknown_argument_with_nested_list_value_returns_graphql_error(): void
  {
    $response = $this->postJson(self::ENDPOINT, [
      'query' => '{ __typename(nosuchargument: [[1, 2], [3]]) }',
    ]);

    $response->assertStatus(200);
    $this->assertStringContainsString('Unknown argument', $response->json('errors.0.message'));
  }

  public function test_unknown_argument_with_scalar_and_object_values_still_return_graphql_error(): void
  {
    foreach (['1', '{a: 1}', '"str"'] as $value) {
      $response = $this->postJson(self::ENDPOINT, [
        'query' => '{ __typename(nosuchargument: ' . $value . ') }',
      ]);

      $response->assertStatus(200);
      $this->assertStringContainsString('Unknown argument', $response->json('errors.0.message'));
    }
  }

  public function test_known_argument_with_wrong_list_value_is_still_validated(): void
  {
    $response = $this->postJson(self::ENDPOINT, [
      'query' => '{ __type(name: [1]) { name } }',
    ]);

    $response->assertStatus(200);
    $this->assertStringContainsString(
      'String',
      $response->json('errors.0.message'),
      'Валидация значений известных аргументов не должна отключаться фиксом'
    );
  }
}
