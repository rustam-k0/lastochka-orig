<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopZone extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shop_id', 'name', 'delivery_price', 'min_order_price', 'free_delivery_from',
        'delivery_type', 'delivery_days', 'delivery_lead_minutes', 'is_active', 'assembly_price',
        'delivery_price_ranges',
    ];

    protected $casts = [
        'delivery_days' => 'array',
        'delivery_lead_minutes' => 'integer',
        'is_active' => 'boolean',
        'assembly_price' => 'integer',
        // Ступени стоимости доставки от суммы заказа (MID-280): [{from_amount, price}]
        'delivery_price_ranges' => 'array',
    ];

    public function coordinates(): HasMany
    {
        return $this->hasMany(ZoneCoordinate::class)->orderBy('position');
    }
}
