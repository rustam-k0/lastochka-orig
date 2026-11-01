<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperConvertor
 */
class Convertor extends Model
{
  use SoftDeletes;

  public function categories(): ?BelongsToMany
  {
    return $this->belongsToMany(Category1C::class, 'join_convertors_categories', 'convertor_id', 'category_1c_id');
  }

  public function products(): ?BelongsToMany
  {
    return $this->belongsToMany(Product1C::class, 'join_convertors_products', 'convertor_id', 'product_1c_id');
  }
}
