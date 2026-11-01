<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderBonus extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'type',
        'percent',
        'eligible_amount',
        'amount',
        'requested_amount',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'eligible_amount' => 'integer',
            'amount' => 'integer',
            'requested_amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
