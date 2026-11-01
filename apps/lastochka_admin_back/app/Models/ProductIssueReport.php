<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductIssueReport extends Model
{
    protected $fillable = [
        'client_id',
        'product_id',
        'shop_id',
        'email',
        'type',
        'message',
    ];

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('created_at');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
