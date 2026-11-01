<?php

namespace App\Filters;

class AssemblyItemFilter extends QueryFilter
{

  public function assembly_id($value)
  {
    $this->builder->where('assembly_id',$value);
  }

  public function statuses_id($value)
  {
    $this->builder->whereIn('status_id',$value);
  }

}
