<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


/**
 * @mixin IdeHelperDefaultAttribute
 */
class DefaultAttribute extends Model
{
  protected $fillable = ['attribute_id', 'fill_percent'];
}
