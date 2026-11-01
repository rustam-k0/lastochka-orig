<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Журнал синхронизации заказа с 1С.
 * Таблица создаётся и наполняется сервисом 1c_parser; admin_back только читает.
 * У 1c_parser собственная БД (mendev_1c), отдельная от БД admin_back (mendev_djami),
 * поэтому читаем через подключение `one_c` (config/database.php, env DB_1C_DATABASE).
 * Связь с заказом admin_back — по shop_order_id (= orders.uuid сторфронта).
 */
class OrderSyncLog extends Model
{
    protected $connection = 'one_c';

    protected $table = 'order_1c_sync_logs';

    protected $casts = [
        'order_id' => 'integer',
        'shop_order_id' => 'integer',
        'attempt_number' => 'integer',
    ];
}
