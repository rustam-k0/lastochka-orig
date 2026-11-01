<?php

namespace App\Traits;

use App\Models\Img;
use App\Models\JoinImg;
use App\Models\Status;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

trait HasImage
{

  /**
   * Получение связки главной фотографии
   */
  public function joinImg(): BelongsTo
  {
    return $this->belongsTo(JoinImg::class, "id", "item_id")->where([
      ["head_id", Status::CODE_HEAD_IMG],
      ["table_name", self::getTable()],
    ]);
  }

  /**
   * Получение главной фотографии
   */
  public function img(): HasOneThrough
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
      $table_name = $this->getTable();
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
      $join->img->links()->delete();
    }
  }

  public function deleteImg(JoinImg $join): void
  {
    $join->delete();
  }


  /**
   * Обновление связки нескольких изображений
   * @param HasMany $join
   * @param $img_arr
   * @return void
   */
  public function updateManyImages(HasMany $join, $img_arr): void //TODO привести в нормальный вид
  {
    $img_ids = [];

    $new_join = $join->clone();
    $delete_join = $join->clone();

    $join_model = $join->first();


    foreach ($img_arr as $img) {
      if (isset($img['id'])) {
        /**
         * Обновляю существующую связку
         */
        $join = clone $new_join;
        $item = $join->where('img_id', $img['id'])->first();
        $join = clone $new_join;

        if ($item && $item->img) {
          $item->img()->update($img);
          $img_ids[] = $item->img_id;
        }
      } else {
        /**
         * Если id не отправлен, то это новое изображение
         */
        if ($img == null) {
          continue;
        }

        $saved = $this->saveImg($img, $join_model->head_id, $join_model->res_id);
        $img_ids[] = $saved->img_id;
      }
    }

    /**
     * Удаляю неотправленные связки
     */
    $delete_img_arr = $delete_join->whereNotIn('img_id', $img_ids)->get();

    foreach ($delete_img_arr as $item) {
      $this->deleteImg($item);
    }

  }
}
