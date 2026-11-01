<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class SupplementValue extends Model
{
  use ModelTools, SoftDeletes;


  protected $fillable = [
    'title',
    'price',
    'supplement_id',
  ];


}
