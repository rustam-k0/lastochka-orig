<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка сотрудника-сборщика к зоне сборки в рамках магазина.
 * Справочник привязок ведётся в admin_back (общая БД).
 *
 * date = null → постоянная привязка; date = дата → на конкретный день.
 */
class UserAssemblyZone extends Model
{
  protected $fillable = [
    'user_id',
    'shop_id',
    'assembly_zone_id',
    'date',
  ];

  protected $casts = [
    'date' => 'date',
  ];

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function assemblyZone(): BelongsTo
  {
    return $this->belongsTo(AssemblyZone::class);
  }
}
