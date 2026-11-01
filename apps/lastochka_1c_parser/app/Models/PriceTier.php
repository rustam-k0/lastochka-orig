<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Ступень «цены от количества» (MID-308): цена за базовую единицу товара,
 * действующая при количестве от min_quantity. Хранится по типу цены, как prices.
 */
class PriceTier extends Model
{
    protected $fillable = [
        'product_id',
        'price_type',
        'min_quantity',
        'value',
    ];

    protected $casts = [
        'min_quantity' => 'float',
        'value' => 'float',
    ];

    /**
     * Готова ли таблица price_tiers к работе (MID-308). Окно выкладки: git pull →
     * composer install → migrate --force → рестарт воркеров. Воркер очереди,
     * впервые загрузивший новый код до миграции, иначе падает на первом же
     * обращении к price_tiers внутри транзакции чанка обмена — исключение
     * откатывает чанк целиком вместе с ценами и остатками.
     *
     * Положительный результат запоминается статически на процесс (таблица не
     * исчезает без рестарта). Отрицательный результат НЕ запоминается: иначе
     * долгоживущий воркер, заставший окно выкладки, игнорировал бы лесенки до
     * собственного рестарта даже после того, как миграция накатилась. Из-за
     * этого метод не годится для вызова на каждое предложение выгрузки —
     * вызывающий код кэширует его результат на свой экземпляр (на время
     * одного чанка/события), как и предполагает финальное ревью ветки.
     */
    private static bool $tableReady = false;

    public static function tableReady(): bool
    {
        if (self::$tableReady) {
            return true;
        }

        if (Schema::hasTable('price_tiers')) {
            self::$tableReady = true;
        }

        return self::$tableReady;
    }

    /**
     * Сбрасывает статический кэш готовности таблицы.
     *
     * @internal только для тестов, которые удаляют/пересоздают price_tiers
     *           в рамках одного PHP-процесса (RefreshDatabase на sqlite
     *           откатывает сам DROP TABLE, но не PHP static-состояние).
     */
    public static function forgetTableReady(): void
    {
        self::$tableReady = false;
    }

    /**
     * Товар
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
