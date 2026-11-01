<?php

namespace App\GraphQL\Validators\ShopZone;

use App\Models\Shop;
use App\Rules\DeliveryPriceRangesRule;
use App\Services\Pricing\DeliveryPriceLadder;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ShopZoneCreteOrUpdateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'name' => ['nullable', 'string', 'max:255'],
      'id' => [Rule::exists('shop_zones', 'id')->where('shop_id', $this->arg('shop_id'))],
      'geojson' => ['required_without:id'],
      'shop_id' => ['required', Rule::exists((new Shop())->getTable(), 'id')->whereNull('deleted_at')],
//      'type_id' => ['required', Rule::in([
//        Type::ZONE_SMALL,
//        Type::ZONE_MIDDLE,
//        Type::ZONE_LARGE,
//      ])],
      'delivery_price' => ['required', 'gte:0'],
      'min_order_price' => ['required', 'gte:0'],
      'free_delivery_from' => ['nullable', 'integer', 'gte:0'],
      // Стоимость доставки от суммы заказа (MID-280): бизнес-правила списка
      // целиком в DeliveryPriceRangesRule (ключ delivery_price_ranges),
      // структура строк поэлементно (ключи delivery_price_ranges.N.*)
      'delivery_price_ranges' => [
        'nullable',
        'array',
        'max:' . DeliveryPriceLadder::MAX_RANGES,
        new DeliveryPriceRangesRule($this->arg('delivery_price'), $this->arg('delivery_type')),
      ],
      'delivery_price_ranges.*.from_amount' => ['required', 'integer', 'min:0', 'max:' . DeliveryPriceLadder::MAX_VALUE, 'distinct'],
      'delivery_price_ranges.*.price' => ['required', 'integer', 'min:0', 'max:' . DeliveryPriceLadder::MAX_VALUE],
      'delivery_days' => ['nullable', 'array'],
      'delivery_days.*' => ['integer', 'between:1,7'],
      // Минуты; верхняя граница — 7 суток
      'delivery_lead_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
      'assembly_price' => ['nullable', 'integer', 'min:0'],
      'is_active' => ['nullable', 'boolean'],
    ];
  }

  public function messages(): array
  {
    return [
      'delivery_price_ranges.max' => 'Не больше 20 диапазонов.',
      'delivery_price_ranges.*.from_amount.distinct' => 'Суммы диапазонов не должны повторяться.',
    ];
  }
}
