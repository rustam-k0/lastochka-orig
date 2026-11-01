<?php

namespace App\Filters;

use App\Models\Attribute;
use App\Models\Category;

class AttributeFilter extends QueryFilter
{
  public function category_id($val)
  {
    $this->builder->withCategory($val);
  }

}
