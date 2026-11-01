<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ClientIdentityLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'anon_client_id',
        'user_id',
        'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];
}
