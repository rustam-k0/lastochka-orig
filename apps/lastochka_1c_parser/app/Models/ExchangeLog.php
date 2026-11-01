<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeLog extends Model
{
    use HasFactory;

    public const TYPE_CATALOG_IMPORT = 'catalog_import';
    public const TYPE_OFFERS_IMPORT = 'offers_import';
    public const TYPE_RESTS_IMPORT = 'rests_import';
    public const TYPE_ORDERS_EXPORT = 'orders_export';
    public const TYPE_ORDERS_IMPORT = 'orders_import';
    /** Шаг deactivate от 1С: удаление товаров, не попавших в полную выгрузку. */
    public const TYPE_DEACTIVATE = 'deactivate';

    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'type',
        'status',
        'message',
        'records_count',
        'started_at',
        'finished_at',
        'shop_id',
    ];

    protected $casts = [
        'records_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Начать логирование обмена
     */
    public static function start(string $type): self
    {
        return static::create([
            'type' => $type,
            'status' => self::STATUS_STARTED,
            'started_at' => now(),
        ]);
    }

    /**
     * Завершить успешно
     */
    public function success(int $recordsCount = 0, ?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'records_count' => $recordsCount,
            'message' => $message,
            'finished_at' => now(),
        ]);
    }

    /**
     * Завершить с ошибкой
     */
    public function error(string $message, int $recordsCount = 0): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'message' => $message,
            'records_count' => $recordsCount,
            'finished_at' => now(),
        ]);
    }

    /**
     * Добавить к сообщению
     */
    public function appendMessage(string $message): void
    {
        $current = $this->message ?? '';
        $this->message = trim($current . "\n" . $message);
        $this->save();
    }

    /**
     * Scope: по типу
     */
    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Длительность обмена в секундах
     */
    public function getDurationAttribute(): ?int
    {
        if (!$this->finished_at || !$this->started_at) {
            return null;
        }

        return $this->finished_at->diffInSeconds($this->started_at);
    }
}
