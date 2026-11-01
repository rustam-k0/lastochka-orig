<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Сетевой принтер печати. Справочник ведётся в admin_back (общая БД),
 * здесь только чтение — отдаём список курьерскому приложению.
 */
class Printer extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'title',
    'ip_address',
    'port',
    'is_active',
  ];

  protected $casts = [
    'is_active' => 'boolean',
    'port' => 'integer',
  ];

  public function scopeActive($query)
  {
    return $query->where('is_active', true);
  }
}
