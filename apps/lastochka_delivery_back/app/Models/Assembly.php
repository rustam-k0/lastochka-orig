<?php

namespace App\Models;

use App\Models\Scopes\NotFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperAssembly
 */
class Assembly extends Model
{
  use SoftDeletes;

  const MAX_ORDERS_COUNT = 2;

  protected $fillable = [
    'user_id',
    'order_id',
    'finished_at',
    'cooking_started_at',
  ];

  protected static function booted(): void
  {
    static::addGlobalScope(new NotFinished());
  }

  public function assemblyItems(): ?HasMany
  {
    return $this->hasMany(AssemblyItem::class);
  }

  public function order(): BelongsTo
  {
    return $this->belongsTo(Order::class);
  }

  public function packages(): HasMany
  {
    return $this->hasMany(AssemblyPackage::class);
  }

  public function getAssemblyItemsSum()
  {
    return $this->assemblyItems?->sum('price');
  }

  public function getAssemblyItemsCollectedSum()
  {
    return $this->assemblyItems?->where('status_id', Status::PRODUCT_ASSEMBLED)->sum('price');
  }

  public function getAssemblyItemsCollectedPercent(): int
  {
    $items_count = $this->assemblyItems?->count();
    $assembled_count = $this->assemblyItems?->whereIn('status_id', [Status::PRODUCT_ASSEMBLED, Status::PRODUCT_CANCELED])->count();
    if ($assembled_count == 0) {
      return 0;
    }

    // Вычисляем процент собранных
    $assembled_percent = ($assembled_count / $items_count) * 100;

    return (int)$assembled_percent;
  }

  public function scopeAuth($query)
  {
    $query->where('user_id', \Auth::id());
  }

}
