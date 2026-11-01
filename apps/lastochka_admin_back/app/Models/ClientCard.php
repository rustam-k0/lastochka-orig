<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientCard extends Model
{
  use ModelTools, SoftDeletes;

  protected $table = 'client_cards';

  protected $fillable = [
    'client_id',
    'binding_id',
    'masked_pan',
    'expiry_date',
    'selected',
  ];

  public function client(): BelongsTo
  {
    return $this->belongsTo(Client::class);
  }
}
