<?php

namespace App\GraphQL\Mutations\Attribute;

use App\Models\Attribute;
use App\Models\DefaultAttribute;
use App\Models\Type;

final class AttributeUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $attribute = Attribute::find($args["id"]);

    if ($attribute->type_id == Type::ATTRIBUTE_SELECT_ONE && !empty($args["values"])) {
      $this->createAttributeValues($attribute, $args["values"]);
    }
    $attribute->update($args);

    if (isset($args['is_default'])) {
      if ($args['is_default']) {
        DefaultAttribute::firstOrCreate(['attribute_id' => $attribute->id], ['fill_percent' => 0]);
      } else {
        DefaultAttribute::where('attribute_id', $attribute->id)->delete();
      }
    }

    return [
      "code" => 200,
      "message" => "OK"
    ];
  }

  private function createAttributeValues(Attribute $attribute, array $values): void
  {
    $ids = [];
    foreach ($values as $value) {
      if (isset($value["id"])) {
        $attribute->attributeValues()->find($value["id"])->update($value);
        $ids[] = $value["id"];
      } else {
        $ids[] = $attribute->attributeValues()->create($value)->id;
      }
    }

    $attribute->attributeValues()->whereNotIn("id", $ids)->delete();
  }
}
