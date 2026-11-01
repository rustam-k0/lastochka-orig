<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\ModelTools;
use App\Traits\HasImage;
use Illuminate\Database\Eloquent\SoftDeletes;


class AppInfo extends Model
{

  protected $fillable = [
    'version'
  ];


}
