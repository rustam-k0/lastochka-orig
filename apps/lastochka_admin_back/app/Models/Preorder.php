<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Preorder extends Model
{
    protected $fillable = [
        'client_id',
        'product_id',
        'shop_id',
        'full_name',
        'phone',
        'email',
        'quantity',
        'comment',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

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
