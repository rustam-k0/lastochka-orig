<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Прогресс async-job'а SyncCategoryMappingsJob по магазину.
 *
 * См. docs/superpowers/specs/2026-05-05-1c-categories-mapping-design.md §3.4.
 *
 * @mixin IdeHelperCategory1cSyncBatch
 */
class Category1cSyncBatch extends Model
{
  use HasFactory;

  protected $table = 'category_1c_sync_batches';

  protected $fillable = [
    'shop_id',
    'status',
    'progress_total',
    'progress_done',
    'started_at',
    'completed_at',
    'error',
    'metadata',
  ];

  protected $casts = [
    'progress_total' => 'int',
    'progress_done' => 'int',
    'started_at' => 'datetime',
    'completed_at' => 'datetime',
    'metadata' => 'array',
  ];

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }
}
