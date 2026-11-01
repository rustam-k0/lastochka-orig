<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperUserShop
 */
class UserShop extends Model
{
  use SoftDeletes;

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

}
