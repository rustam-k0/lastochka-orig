<?php

namespace App\GraphQL\Validators\Shop;

use App\Models\Type;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ShopCreateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'title' => ['required', 'max:255'],
      'city_id' => ['nullable', 'exists:cities,id'],
      'phone' => ['nullable', 'max:255'],
      'email' => ['nullable', 'email', 'max:255'],
      'slot_time' => ['nullable', 'gt:0'],
      'slot_max_orders' => ['nullable', 'gt:0'],
      'longitude' => ['nullable'],
      'latitude' => ['nullable'],
      'address' => ['nullable', 'max:255'],
      'commission' => ['nullable', 'gt:0'],
      'deposit' => ['nullable'],
      'type_id' => ['required', Rule::in([Type::SHOP_TYPE_STORE, Type::SHOP_TYPE_CAFE])],
      'schedule' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'categories.*' => [Rule::exists('categories', 'id')->whereNull('deleted_at')],
      'payment_type_id' => ['nullable', Rule::in([
        Type::SHOP_PAYMENT_ASSEMBLY,
        Type::SHOP_PAYMENT_ORDER_CREATED,
        Type::SHOP_PAYMENT_ASSEMBLY_STARTED,
        Type::SHOP_PAYMENT_ASSEMBLY_DELIVERED,
      ])],
      'ignore_stock' => ['boolean'],
      'is_bonus_enabled' => ['nullable', 'boolean'],
      'bonus_earn_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
      'bonus_spend_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
      'heavy_order_threshold' => ['nullable', 'integer', 'min:0'],
      'assembly_price' => ['nullable', 'integer', 'min:0'],
      'area_sqm' => ['nullable', 'numeric', 'min:0'],
    ];
  }
}
