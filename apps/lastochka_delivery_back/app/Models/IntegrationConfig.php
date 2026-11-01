<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationConfig extends Model
{
    protected $fillable = [
        'integration_type',
        'driver',
        'credentials',
        'is_active',
    ];

    protected $casts = [
        'credentials' => 'encrypted:json',
        'is_active' => 'boolean',
    ];
}
