<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Префикс штрихкода от 1С (мастер префиксов — 1С).
 * type=card — префикс номеров карт лояльности.
 */
class BarcodePrefix extends Model
{
    public const TYPE_CARD = 'card';

    protected $fillable = ['type', 'value'];
}
