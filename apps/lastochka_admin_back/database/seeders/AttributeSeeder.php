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
        'code' => 'proteins',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 1,
        'percent' => 2,
      ],
      [
        'title' => 'Жиры',
        'code' => 'fats',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 2,
        'percent' => 2,
      ],
      [
        'title' => 'Углеводы',
        'code' => 'carbs',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 3,
        'percent' => 2,
      ],
      [
        'title' => 'Ккал',
        'code' => 'calories',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 4,
        'percent' => 10,
      ],
      [
        'title' => 'Состав',
        'code' => 'composition',
        'type_id' => Type::ATTRIBUTE_TEXT,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 5,
        'percent' => 20,
        'required'=>true
      ],
      [
        'title' => 'Условия хранения',
        'code' => 'storage_conditions',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 1,
        'percent' => 5,
        'required'=>true
      ],
      [
        'title' => 'Срок хранения',
        'code' => 'shelf_life',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 2,
        'percent' => 5,
        'required'=>true
      ],
      [
        'title' => 'Вес брутто',
        'code' => 'gross_weight',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 3,
        'percent' => 3,
      ],
      [
        'title' => 'Вес нетто',
        'code' => 'net_weight',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 4,
        'percent' => 1,
      ],
      [
        'title' => 'Габариты',
        'code' => 'dimensions',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 5,
        'percent' => 2,
      ],
      [
        'title' => 'Тип продукта',
        'code' => 'product_type',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 6,
        'percent' => 1,
      ],
      [
        'title' => 'Страна',
        'code' => 'country',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 7,
        'percent' => 2,
      ],
      [
        'title' => 'Форма выпуска',
        'code' => 'release_form',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 8,
        'percent' => 1,
      ],
      [
        'title' => 'Производитель',
        'code' => 'manufacturer',
        'type_id' => Type::ATTRIBUTE_STRING,
        'category_type_id' => Type::ATTRIBUTE_DEFAULT,
        'position' => 9,
        'percent' => 1,
      ],
    ];


    foreach ($attributes as $attribute) {
      // Код уже занят (в т.ч. trashed-строкой): миграция 2026_07_14_120000 на свежей
      // установке вставляет характеристики раньше сидера, UNIQUE attributes.code
      if (Attribute::withTrashed()->where('code', $attribute['code'])->exists()) {
        continue;
      }

      $percent = $attribute['percent'];
      unset($attribute['percent']);
      $created = Attribute::create($attribute);
      $created->defaultAttribute()->create(['fill_percent' => $percent]);
    }

  }
}
