<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperImg
 */
class Img extends Model
{
  const table = "img";
  protected $table = self::table;

  protected $fillable = ["path", "origin_name", "alt", "size", "type", "source_url"];

  /**
   * source_url — URL, с которого файл скачан обменом 1С (MID-305). Замена
   * файла без нового source_url (ручная замена фото в админке через
   * HasImage::updateImg) сбрасывает источник: иначе следующий обмен с тем же
   * URL счёл бы фото уже скачанным и ручное фото осталось бы вместо фото 1С.
   */
  protected static function booted(): void
  {
    static::updating(function (Img $img) {
      if ($img->isDirty('path') && !$img->isDirty('source_url')) {
        $img->source_url = null;
      }
    });
  }

  public function links(): HasMany
  {
    return $this->hasMany(ImageLink::class)->orderByDesc('updated_at');
  }
}
