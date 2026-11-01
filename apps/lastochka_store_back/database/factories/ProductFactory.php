<?php

namespace Database\Factories;

use App\Helpers\FakerHelper;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Country;
use App\Models\Manufacturer;
use App\Models\ProductAttribute;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ProductFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition()
  {

    $title = $this->faker->colorName . ' ' . $this->faker->randomElement(['костюм', 'ремень', 'кошелек', 'зонт', 'кофе', 'кресло', 'стул', 'стол', 'шампунь', 'гель', 'дезодорант', 'шоколад', 'банан', 'апельсин', 'яблоко', 'автомобиль', 'велосипед', 'какао', 'кофе']);

    return [
      'title' => $title,
      'articul' => rand(111111, 999999),
      'description' => $this->faker->boolean() ? $this->faker->realText(300) : null,
      'meta_title' => $this->faker->boolean(30) ? $this->faker->realText(30) : null,
      'meta_description' => $this->faker->boolean() ? $this->faker->realText(100) : null,
      'meta_keywords' => $this->faker->boolean(40) ? $this->faker->realText(10) : null,
      'status_id' => Status::CODE_ACTIVE,
      'brand_id' => Brand::inRandomOrder()->first()->id,
      'manufacturer_id' => Manufacturer::inRandomOrder()->first()->id,
      'country_id' => Country::inRandomOrder()->first()->id,
      'unit_type_id' => $this->faker->randomElement([Type::UNIT_GRAM,Type::UNIT_PIECE]),
    ];
  }

  protected function callAfterCreating(Collection $instances, ?Model $parent = null)
  {
    parent::callAfterCreating($instances, $parent);
    foreach ($instances as $instance) {
      Remain::factory()->product($instance->id)->create();
      $instance->categories()->sync([Category::whereNotNull('parent_id')->inRandomOrder()->first()?->id]);
      $this->createProductAttributes($instance->id);
      $instance->saveImg(FakerHelper::generateFakeImage());
    }
  }

  private function createProductAttributes($product_id)
  {
    $attributes = Attribute::whereHas('defaultAttribute')->get();
    foreach ($attributes as $attribute) {
      $value = $this->generateAttributeValue($attribute->type_id);
      $product_attribute = [
        'attribute_id' => $attribute->id,
        'product_id' => $product_id
      ];

      if ($value) {
        $product_attribute = array_merge($product_attribute, $value);
      }

      ProductAttribute::create($product_attribute);

    }
  }

  private function generateAttributeValue($type_id): ?array
  {
    return match ($type_id) {
      Type::ATTRIBUTE_STRING => ['string_value' => $this->faker->realText(30)],
      Type::ATTRIBUTE_INT => ['int_value' => rand(10, 50)],
      Type::ATTRIBUTE_FLOAT => ['float_value' => rand(10, 50)],
      Type::ATTRIBUTE_TEXT => ['text_value' => $this->faker->realText(100)],
      default => null
    };
  }
}
