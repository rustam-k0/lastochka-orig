<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Model;

class EntityHash extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'shop_code',
        'hash',
    ];

    protected $table = 'entity_hashes';
}
