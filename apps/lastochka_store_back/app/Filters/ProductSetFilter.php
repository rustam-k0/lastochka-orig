<?php

namespace App\Filters;

class ProductSetFilter extends QueryFilter
{

  public function main($value)
  {
    $this->builder->where('main',(int)$value);
  }

}
