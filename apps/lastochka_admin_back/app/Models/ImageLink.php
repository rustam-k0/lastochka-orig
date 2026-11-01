<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class ImageLink extends Model
{

  protected $fillable = [
    'img_id',
    'query',
    'path'
  ];


}
