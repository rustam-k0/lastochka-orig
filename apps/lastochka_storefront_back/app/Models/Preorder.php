<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PreorderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Preorder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'store_id',
        'full_name',
        'phone',
        'email',
        'quantity',
        'comment',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => PreorderStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
