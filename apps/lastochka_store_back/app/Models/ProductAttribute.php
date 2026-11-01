<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


class ProductAttribute extends Model
{
  use ModelTools, SoftDeletes;

  public function attribute(): ?BelongsTo
  {
    return $this->belongsTo(Attribute::class);
  }

  public function attributeValue(): ?HasOne
  {
    return $this->hasOne(AttributeValue::class,'id','attribute_value_id');
  }

}
