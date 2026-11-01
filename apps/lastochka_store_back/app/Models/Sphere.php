<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;


class Sphere extends Model
{
  use HasFactory, ModelTools, HasImage, SoftDeletes;


  protected $fillable = [
    'title',

  ];


  public function img(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_HEAD_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_HEAD_IMG],
      ["table_name", $this->getTable()]
    ]);
  }

  public function shops(): HasMany
  {
    return $this->hasMany(Shop::class);
  }

}
