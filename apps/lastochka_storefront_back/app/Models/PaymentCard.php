<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PaymentCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'payment_method_id',
        'card_type',
        'last_four',
        'expiry_month',
        'expiry_year',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
