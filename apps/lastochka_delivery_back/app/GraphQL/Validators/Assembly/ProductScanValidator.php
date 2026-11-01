<?php

namespace App\GraphQL\Validators\Assembly;

use Nuwave\Lighthouse\Validation\Validator;

final class ProductScanValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'assembly_id' => ['required', 'integer'],
      'product_id' => ['nullable', 'integer'],
      'assembly_item_id' => ['nullable', 'integer'],
      'barcode' => ['nullable', 'string', 'max:64'],
      'method' => ['required', 'in:qr,manual'],
      'result' => ['required', 'in:ok,error'],
    ];
  }
}
