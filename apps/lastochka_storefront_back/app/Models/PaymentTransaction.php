<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'client_order_id',
        'type',
        'status',
        'amount',
        'currency',
        'yookassa_payment_id',
        'yookassa_refund_id',
        'payment_method_id',
        'metadata',
        'error_message',
        'decline_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentTransactionType::class,
            'status' => PaymentTransactionStatus::class,
            'amount' => 'decimal:2',
            'metadata' => 'json',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
