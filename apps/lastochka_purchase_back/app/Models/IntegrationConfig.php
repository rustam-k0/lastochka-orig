<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationConfig extends Model
{
    protected $table = 'integration_configs';

    protected $fillable = [
        'integration_type',
        'driver',
        'credentials',
        'is_active',
        'shop_id',
    ];

    protected $casts = [
        'credentials' => 'encrypted:json',
        'is_active'   => 'boolean',
    ];
}
