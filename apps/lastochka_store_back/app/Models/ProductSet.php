<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;


class ProductSet extends Model
{
  use HasFactory, ModelTools, HasImage, SoftDeletes;

  public function products(): ?BelongsToMany
  {
    return $this->belongsToMany(Product::class);
  }

  public function categories(): ?BelongsToMany
  {
    return $this->belongsToMany(SetCategory::class,'join_set_categories_product_sets','product_set_id','category_id');
  }
}
