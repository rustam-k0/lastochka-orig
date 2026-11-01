<?php

namespace App\Filters;

class BrandFilter extends QueryFilter
{
  public function categories_page($value)
  {
    $this->builder->where('categories_page', (int)$value);
  }

  public function is_popular($value)
  {
    $this->builder->where('is_popular', (int)$value);
  }
}
