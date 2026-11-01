<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assembly extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'order_id',
        'finished_at',
        'cooking_started_at',
    ];
}
