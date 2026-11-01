<?php

namespace App\Filters;

class UserFilter extends QueryFilter
{
  public function shop_id($val)
  {
    $this->builder->whereRelation('shops', 'shop_id', $val);
  }
}
