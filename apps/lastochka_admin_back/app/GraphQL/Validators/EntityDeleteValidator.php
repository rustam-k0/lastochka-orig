<?php

namespace App\GraphQL\Validators;

use App\Models\Announcement;
use App\Models\AssemblyZone;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\MerchantReview;
use App\Models\ProductReview;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class EntityDeleteValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $table = $this->arg('table');

    $available_tables = [
      (new Announcement())->getTable(),
      (new AssemblyZone())->getTable(),
      (new Attribute())->getTable(),
      (new Category())->getTable(),
      (new MerchantReview())->getTable(),
      (new ProductReview())->getTable(),
    ];

    $rules = [
      'table' => ['required', Rule::in($available_tables)],
    ];

    if (in_array($table, $available_tables)) {
      $exists = Rule::exists($table, 'id');

      // Для таблиц с SoftDeletes исключаем уже удалённые записи. Таблицы без
      // soft deletes (напр. assembly_zones) не имеют колонки deleted_at —
      // безусловный whereNull('deleted_at') упал бы SQL-ошибкой Unknown column.
      if (Schema::hasColumn($table, 'deleted_at')) {
        $exists->whereNull('deleted_at');
      }

      $rules['id'] = ['required', $exists];
    }

    return $rules;
  }
}
