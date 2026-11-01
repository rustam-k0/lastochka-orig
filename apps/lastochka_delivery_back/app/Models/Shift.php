<?php

namespace App\Models;

use App\Models\Scopes\NotFinished;
use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperShift
 */
class Shift extends Model
{
  use  ModelTools, HasImage, SoftDeletes;

  protected $fillable = [
    'shop_id',
    'status_id',
    'finished_at',
  ];

  protected static function booted(): void
  {
    static::addGlobalScope(new NotFinished());
  }

  public function shop()
  {
    return $this->belongsTo(Shop::class);
  }

  public function scopeAuth($query)
  {
    $query->where('user_id', \Auth::id());
  }

  /**
   * Закрыть смену: единая точка для мутации ShiftFinish и finishShifts,
   * чтобы shift.ended публиковался при любом способе закрытия (#11599).
   */
  public function finish(): void
  {
    $this->update([
      'finished_at' => now(),
    ]);

    // Аналитика: конец рабочей сессии сотрудника (LASTOCHKI-JAMI-11659, B2E #11599)
    \App\Services\AnalyticsEvents::shiftEnded($this);
  }

  public static function finishShifts()
  {
    $unfinished_shifts = Shift::where('finished_at', null)->get();

    foreach ($unfinished_shifts as $unfinished_shift) {
      $unfinished_shift->finish();
    }
  }
}
