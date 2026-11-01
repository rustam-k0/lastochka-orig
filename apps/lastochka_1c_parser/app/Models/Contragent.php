<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contragent extends Model
{
    protected $fillable = [
        'external_id', 'loyalty_id', 'card_number', 'name', 'surname', 'patronymic',
        'phone', 'email', 'birthday', 'gender', 'bonus_balance',
        'shop_id', 'exported_at', 'received_at',
    ];

    protected $casts = [
        'birthday' => 'date',
        'bonus_balance' => 'integer',
        'exported_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
