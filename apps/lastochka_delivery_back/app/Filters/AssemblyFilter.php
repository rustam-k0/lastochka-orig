<?php

namespace App\Filters;

use App\Models\Status;

class AssemblyFilter extends QueryFilter
{

  public function status(int $value): void
  {
    parent::status($value);

    if ($value == Status::ORDER_ASSEMBLED) {

    }
  }

}
