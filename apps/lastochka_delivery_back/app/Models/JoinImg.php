<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperJoinImg
 */
class JoinImg extends Model
{
  use ModelTools,SoftDeletes;

  const table = "join_img";
  protected $table = self::table;

  protected $fillable = [
    "item_id",
    "img_id",
    "head_id",
    "table_name",
    "status_id",
  ];

  /**
   * Получение фотографии
   */
  public function img(): ?BelongsTo
  {
    return $this->belongsTo(Img::class, "img_id", "id");
  }

}
