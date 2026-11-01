<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись использования промокода клиентом — для лимитов max_uses и max_uses_per_client.
 * Создаётся при оформлении заказа (PromocodeUsageTracker), не при применении к корзине.
 * Лимиты считают только активные использования — см. {@see self::scopeActive()}.
 */
final class PromocodeUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'promocode_id',
        'user_id',
        'order_id',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Использования, которые расходуют лимиты промокода: заказ не отменён.
     *
     * Отменённый заказ промокод не «сжигает» — клиент может применить его повторно.
     * Запись при отмене не удаляется: отмена приходит многими путями (клиент, курьерка
     * и админка через RabbitMQ без событий модели, автоотмены), а статус заказа — один
     * источник правды для всех.
     *
     * Platform-wide промокод маркетплейса пишется на первый sub-order client-order'а,
     * поэтому освобождается, только когда отменены все sub-orders.
     * Использование без заказа (заказ удалён) считается активным.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereDoesntHave('order', function (Builder $order) {
            $order->withTrashed()
                ->where('status', OrderStatus::Cancelled->value)
                ->where(function (Builder $cancelled) {
                    $cancelled->whereNull('client_order_id')
                        ->orWhereNotExists(function ($sibling) {
                            $sibling->selectRaw('1')
                                ->from('orders as sibling_orders')
                                ->whereColumn('sibling_orders.client_order_id', 'orders.client_order_id')
                                ->where('sibling_orders.status', '!=', OrderStatus::Cancelled->value);
                        });
                });
        });
    }
}
