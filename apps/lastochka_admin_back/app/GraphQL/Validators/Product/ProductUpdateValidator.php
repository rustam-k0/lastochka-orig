<?php

namespace App\GraphQL\Validators\Product;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Supplement;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Rules\CategoryHasAttribute;
use App\Rules\ProductAttributeRule;
use App\Rules\ShopHasProductRule;
use App\Rules\UniqueArticulPerShopRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductUpdateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $isUpdate = $this->arg('id') !== null;

    return [
      'id' => ['bail','nullable', Rule::exists((new Product())->getTable())->whereNull('deleted_at'), new ShopHasProductRule()],
      'title' => [$isUpdate ? 'nullable' : 'required', 'max:255'],
      'articul' => ['nullable', 'string', 'max:255', new UniqueArticulPerShopRule($this->resolveShopId(), $this->arg('id'))],
      'additional_info' => ['nullable', 'string', 'max:1000'],
      'meta_title' => ['nullable', 'max:255'],
      'meta_description' => ['nullable', 'max:255'],
      'category_ids' => ['nullable', 'array'],
      'category_ids.*' => ['integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
      'attributes' => [new ProductAttributeRule()],
      'attributes.*.attribute_id' => ['bail', 'required', new CategoryHasAttribute($this->arg('category_ids') ?? [])],
      'attributes.*.id' => ['nullable', Rule::exists((new ProductAttribute())->getTable(), 'id')->whereNull('deleted_at')],
      'price' => [$this->priceRequiredRule(), 'gt:0'],
      'quantity' => ['nullable', 'integer', 'gte:0'],
      'weight' => ['gt:0'],
      'cook_start_time' => ['bail', 'nullable', 'date_format:H:i', 'before:cook_end_time'],
      'cook_end_time' => ['bail', 'nullable', 'date_format:H:i', 'after:cook_start_time'],
      'info_weight' => ['nullable'],
      'manual_quantum_step' => ['nullable', 'integer', 'min:1', 'max:100000'],
      'manual_quantum_unit_type_id' => ['nullable', 'integer', Rule::in([
        Type::INFO_WEIGHT_GRAM,
        Type::INFO_WEIGHT_M_LITER,
        Type::INFO_WEIGHT_PIECE,
      ])],
      'info_weight_type_id' => [$isUpdate ? 'nullable' : 'required', Rule::in([
        Type::INFO_WEIGHT_PIECE,
        Type::INFO_WEIGHT_GRAM,
        Type::INFO_WEIGHT_KILOGRAM,
        Type::INFO_WEIGHT_LITER,
        Type::INFO_WEIGHT_M_LITER,
      ])],
      'shop_id' => [$this->adminRequiredRule(), Rule::exists('shops', 'id')],
      'supplements'=>['nullable'],
      'supplements.*.id'=>['nullable',Rule::exists('supplements','id')],
      'supplements.*.title'=>['required','max:255'],
      'supplements.*.type'=>['required',Rule::in([Supplement::TYPE_ONE,Supplement::TYPE_MANY])],
      'supplements.*.is_required'=>['nullable'],
      'supplements.*.values'=>['required'],
      'supplements.*.values.*.id'=>['nullable',Rule::exists('supplement_values','id')],
      'supplements.*.values.*.title'=>['required','max:255'],
      'supplements.*.values.*.price'=>['nullable','gte:0','required_without:supplements.*.values.*.product_id'],
      'is_culinary' => ['nullable', 'boolean'],
      'pickup_only' => ['nullable', 'boolean'],
      'adult_only' => ['nullable', 'boolean'],
      'is_marked' => ['nullable', 'boolean'],
      'is_new' => ['nullable', 'boolean'],
      'is_hurry_up' => ['nullable', 'boolean'],
      'ready_fixed_time' => ['nullable', 'date_format:H:i'],
    ];
  }

  /**
   * Глобальный админ обязан указать магазин товара — кроме сетевого каталога:
   * там карточка одна на всю сеть, магазин в админке не выбирается, и
   * ProductUpdate подставляет якорный магазин сети.
   */
  public function adminRequiredRule(): \Illuminate\Validation\Rules\RequiredIf
  {
    return Rule::requiredIf(
      fn() => Auth::user()?->isGlobalScope() && !$this->networkCatalogEnabled()
    );
  }

  /**
   * Цена обязательна везде, кроме сетевого каталога: там цену ведёт 1С
   * пер-магазин, у карточки нет «своей» цены и админка сохраняет её без price.
   */
  private function priceRequiredRule(): \Illuminate\Validation\Rules\RequiredIf
  {
    return Rule::requiredIf(fn() => !$this->networkCatalogEnabled());
  }

  /**
   * Магазин товара для проверки уникальности артикула. Зеркалит резолюцию
   * shop_id в ProductUpdate::updateOrCreateProduct() — для не-глобальных
   * админов shop_id не приходит в аргументах, а берётся из привязки юзера,
   * в сетевом каталоге у глобального админа — из якорного магазина сети.
   */
  private function resolveShopId(): ?int
  {
    if (Auth::user()?->isGlobalScope()) {
      return $this->arg('shop_id')
        ?? ($this->networkCatalogEnabled() ? app(NetworkCatalogMode::class)->anchorShopId() : null);
    }

    return Auth::user()?->shops()->first()->shop_id ?? null;
  }

  private function networkCatalogEnabled(): bool
  {
    return app(NetworkCatalogMode::class)->isEnabled();
  }
}
