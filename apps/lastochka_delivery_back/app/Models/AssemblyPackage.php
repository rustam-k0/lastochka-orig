<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperAssemblyPackage
 */
class AssemblyPackage extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'type_id',
    'order_id',
    'quantity'
  ];


}
