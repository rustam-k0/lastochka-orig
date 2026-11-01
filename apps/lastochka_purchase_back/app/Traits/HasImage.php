<?php

namespace App\Traits;

use App\Models\Img;
use App\Models\JoinImg;
use App\Models\Status;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

trait HasImage
{

  /**
   * Получение связки главной фотографии
   */
  public function joinHeadImg(): BelongsTo
  {
    return $this->belongsTo(JoinImg::class, "id", "item_id")->where([
      ["head_id", Status::CODE_HEAD_IMG],
      ["table_name", self::getTable()],
    ]);
  }

  /**
   * Получение главной фотографии
   */
  public function headImg(): HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_HEAD_IMG],
        ["table_name", self::getTable()],
      ]);
  }

  public function saveImg(array $img, int $head_code = Status::CODE_HEAD_IMG, string $table_name = null): JoinImg
  {
    if (!$table_name) {
      $table_name = self::getTable();
    }
    $img = Img::create($img);

    return JoinImg::create([
      'item_id' => $this->id,
      'img_id' => $img->id,
      'head_id' => $head_code,
      'table_name' => $table_name
    ]);
  }

  public function updateImg(?JoinImg $join, ?array $img): void
  {
    if ($join && !$img) {
      $this->deleteImg($join);
    } elseif (isset($join, $img)) {
      $join->img->update($img);
    }
  }

  public function deleteImg(JoinImg $join): void
  {
    $join->delete();
  }
}
