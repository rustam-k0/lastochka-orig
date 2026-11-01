<?php

namespace App\Filters;

use App\Models\Category;

class ProductFilter extends QueryFilter
{

  public function title(string $value): void
  {
    $this->builder->where(function ($query)use ($value){
      $query->where('title', 'LIKE', "%$value%")->orWhere('articul', 'LIKE', "%$value%");

    });
  }

  public function categories($value): void
  {
    $this->builder->whereHas('categories',function ($query)use ($value){
      $query->whereIn((new Category())->getTable().'.id',$value);
    });
  }

  public function is_culinary(bool $value): void
  {
    $this->builder->where('is_culinary', $value);
  }

  public function pickup_only(bool $value): void
  {
    $this->builder->where('pickup_only', $value);
  }

  public function adult_only(bool $value): void
  {
    $this->builder->where('adult_only', $value);
  }

  public function is_marked(bool $value): void
  {
    $this->builder->where('is_marked', $value);
  }

  public function is_new(bool $value): void
  {
    $this->builder->where('is_new', $value);
  }

  public function is_hurry_up(bool $value): void
  {
    $this->builder->where('is_hurry_up', $value);
  }

}
