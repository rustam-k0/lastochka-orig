<?php

namespace App\Models\Scopes;

use App\Models\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ActiveScope implements Scope
{
  /**
   *  Только активные записи
   */
  public function apply(Builder $builder, Model $model)
  {
    $builder->where('status_id', Status::CODE_ACTIVE);
  }
}
