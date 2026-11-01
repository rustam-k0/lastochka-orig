<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockModeChange extends Model
{
    protected $fillable = [
        'entity_type', 'entity_id',
        'old_mode', 'new_mode',
        'old_fixed', 'new_fixed',
        'user_id',
    ];
}
