<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientBonusTransaction extends Model
{
  const STATUS_PENDING = 'pending';
  const STATUS_APPLIED = 'applied';
  const STATUS_REJECTED = 'rejected';

  protected $fillable = [
    'client_id',
    'operation_id',
    'type',
    'amount',
    'balance_after',
    'source',
    'description',
    'admin_id',
    'status',
    'reject_reason',
    'reject_message',
    'order_external_id',
    'occurred_at',
  ];

  protected $casts = [
    'amount' => 'integer',
    'balance_after' => 'integer',
    'occurred_at' => 'datetime',
  ];

  public function client(): BelongsTo
  {
    return $this->belongsTo(Client::class);
  }

  public function admin(): BelongsTo
  {
    return $this->belongsTo(User::class, 'admin_id');
  }

  /**
   * Та же сортировка, что и в Client::bonusTransactions(): occurred_at пуст
   * у pending и у навсегда отклонённых записей, поэтому голый DESC топит их
   * в конец (NULL last в MySQL). COALESCE с created_at держит их на месте
   * фактического появления в журнале. Нужен списку истории в GraphQL,
   * который читает модель напрямую через пагинацию, а не через связь.
   */
  public function scopeLatestFirst($query)
  {
    return $query
      ->orderByRaw("COALESCE(occurred_at, created_at) DESC")
      ->orderByDesc("id");
  }
}
