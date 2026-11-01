<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperUserWorkingHour
 */
class UserWorkingHour extends Model
{
  use SoftDeletes;

  public function userShop(): BelongsTo
  {
    return $this->belongsTo(UserShop::class);
  }
}
