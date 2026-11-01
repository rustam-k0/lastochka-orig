<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperAdmin
 */
class Admin extends Model
{
  use HasFactory, ModelTools, HasImage, SoftDeletes;

  protected $table = self::table;
  const table = 'models';

  protected $fillable = [

  ];


}
