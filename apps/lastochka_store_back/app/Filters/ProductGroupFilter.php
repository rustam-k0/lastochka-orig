<?php

namespace App\Filters;

class ProductGroupFilter extends QueryFilter
{
  public function stocks_sets($value)
  {
    $this->builder->where('stocks_sets',(int)$value);
  }
  public function sets_cooking($value)
  {
    $this->builder->where('sets_cooking',(int)$value);
  }
  public function after_cooking($value)
  {
    $this->builder->where('after_cooking',(int)$value);
  }

}
