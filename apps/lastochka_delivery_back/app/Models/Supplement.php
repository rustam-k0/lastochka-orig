<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Supplement extends Model
{
  use ModelTools, SoftDeletes;

  const TYPE_ONE = 'one';
  const TYPE_MANY = 'many';

  protected $fillable = [
    'title',
    'product_id',
    'type',
    'is_required',
  ];

  public function values(): \Illuminate\Database\Eloquent\Relations\HasMany
  {
    return $this->hasMany(SupplementValue::class);
  }

}
