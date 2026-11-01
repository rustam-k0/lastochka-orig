<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\Type;
use Illuminate\Database\Seeder;

class AttributeSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    $attributes = [
      [
        'title' => 'Белки',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 1,
        'percent' => 2,
      ],
      [
        'title' => 'Жиры',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 2,
        'percent' => 2,
      ],
      [
        'title' => 'Углеводы',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 3,
        'percent' => 2,
      ],
      [
        'title' => 'Ккал',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 4,
        'percent' => 10,
      ],
      [
        'title' => 'Состав',
        'type_id' => Type::ATTRIBUTE_TEXT,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 5,
        'percent' => 20,
        'required'=>true
      ],
      [
        'title' => 'Условия хранения',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 1,
        'percent' => 5,
        'required'=>true
      ],
      [
        'title' => 'Срок хранения',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 2,
        'percent' => 5,
        'required'=>true
      ],
      [
        'title' => 'Вес брутто',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 3,
        'percent' => 3,
      ],
      [
        'title' => 'Вес нетто',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 4,
        'percent' => 1,
      ],
      [
        'title' => 'Габариты',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 5,
        'percent' => 2,
      ],
    ];


    foreach ($attributes as $attribute) {
      $percent = $attribute['percent'];
      unset($attribute['percent']);
      $created = Attribute::create($attribute);
      $created->defaultAttribute()->create(['fill_percent' => $percent]);
    }

  }
}
