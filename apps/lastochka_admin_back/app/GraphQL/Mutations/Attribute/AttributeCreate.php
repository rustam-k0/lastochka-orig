<?php

namespace App\GraphQL\Mutations\Attribute;

use App\Models\Attribute;
use App\Models\DefaultAttribute;
use App\Models\Type;

final class AttributeCreate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {

    $attribute = Attribute::create($args);

    if ($attribute->type_id == Type::ATTRIBUTE_SELECT_ONE && !empty($args['values'])) {
      $this->createAttributeValues($attribute, $args['values']);
    }

    if (!empty($args['is_default'])) {
      DefaultAttribute::create(['attribute_id' => $attribute->id, 'fill_percent' => 0]);
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  private function createAttributeValues(Attribute $attribute, array $values): void
  {
    foreach ($values as $value) {
      $attribute->attributeValues()->create($value);
    }
  }
}
