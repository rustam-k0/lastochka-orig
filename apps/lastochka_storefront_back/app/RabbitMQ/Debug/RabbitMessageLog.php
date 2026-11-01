<?php

declare(strict_types=1);

namespace App\RabbitMQ\Debug;

use Database\Factories\RabbitMessageLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $direction
 * @property string $routing_key
 * @property string|null $queue_name
 * @property array $payload
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string|null $external_id
 * @property string $status
 * @property string|null $error_message
 * @property string|null $error_trace
 * @property array|null $db_snapshot
 * @property int|null $processing_time_ms
 * @property \Carbon\Carbon $created_at
 */
final class RabbitMessageLog extends Model
{
    /** @use HasFactory<RabbitMessageLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function newFactory(): RabbitMessageLogFactory
    {
        return RabbitMessageLogFactory::new();
    }

    protected $table = 'rabbit_message_log';

    protected $fillable = [
        'direction',
        'routing_key',
        'queue_name',
        'payload',
        'entity_type',
        'entity_id',
        'external_id',
        'status',
        'error_message',
        'error_trace',
        'db_snapshot',
        'processing_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'db_snapshot' => 'array',
            'entity_id' => 'integer',
            'processing_time_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    // ==================== Scopes ====================

    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForEntity($query, string $entityType, ?int $entityId = null)
    {
        $query->where('entity_type', $entityType);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    public function scopeForRoutingKey($query, string $routingKey)
    {
        if (str_contains($routingKey, '*')) {
            $pattern = str_replace(['*', '.'], ['%', '\\.'], $routingKey);

            return $query->where('routing_key', 'like', $pattern);
        }

        return $query->where('routing_key', $routingKey);
    }

    public function scopeCreatedBetween($query, $from, $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    // ==================== Methods ====================

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }
}
