<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentReceiptType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReceiptProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentReceipt extends Model
{
    protected $fillable = [
        'order_id',
        'payment_transaction_id',
        'provider',
        'external_id',
        'yookassa_receipt_id',
        'type',
        'status',
        'fiscal_storage_number',
        'fiscal_document_number',
        'fiscal_attribute',
        'receipt_url',
        'registered_at',
        'amount',
        'items',
        'raw',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => ReceiptProvider::class,
            'type' => PaymentReceiptType::class,
            'status' => PaymentTransactionStatus::class,
            'registered_at' => 'datetime',
            'amount' => 'decimal:2',
            'items' => 'array',
            'raw' => 'array',
            'notified_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
