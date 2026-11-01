<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\ModelTools;
use App\Traits\HasImage;
use Illuminate\Database\Eloquent\SoftDeletes;


class Supplement extends Model
{
  use ModelTools, SoftDeletes;


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
