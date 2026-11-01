<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Состав одного файла полной выгрузки каталога 1С (import___*.xml).
 *
 * 1С режет каталог на файлы по ~1000 товаров, каждый приходит отдельной
 * HTTP-сессией. Чтобы на команде deactivate понять, какие товары в выгрузку
 * не попали, импортёр после успешной обработки файла сохраняет здесь все
 * его Ид. Шаг deactivate объединяет чанки магазина и удаляет их.
 */
class CatalogImportChunk extends Model
{
    protected $fillable = [
        'shop_id',
        'exchange_log_id',
        'external_ids',
        'products_count',
    ];

    protected $casts = [
        'external_ids' => 'array',
        'products_count' => 'integer',
    ];

    public function exchangeLog(): BelongsTo
    {
        return $this->belongsTo(ExchangeLog::class);
    }
}
