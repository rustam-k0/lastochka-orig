<?php

namespace App\Filters;

use App\Services\ModuleConfigService;

class ClientFilter extends QueryFilter
{

  public function title(string $value): void
  {
    $this->builder->where(function ($query)use ($value){
      $query->where(
        \DB::raw(
          "CONCAT(COALESCE(`name`,''), ' ', COALESCE(`surname`,''),' ',COALESCE(`patronymic`,''))"
        ),
        "LIKE",
        "%" . $value . "%"
      )->orWhere("phone","LIKE","%$value%")
        ->orWhere("email","LIKE","%$value%");
    });
  }

  /**
   * Фильтр по флагу B2B. Применяется только когда включён модуль `b2b`
   * (иначе значение игнорируется, чтобы не давать клиенту обойти module toggle).
   */
  public function is_b2b(bool $value): void
  {
    if (!ModuleConfigService::isB2bEnabled()) {
      return;
    }

    $this->builder->where('is_b2b', $value);
  }

}
