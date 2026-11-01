<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentTransactionStatus;
use App\Enums\ReceiptProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderReceiptResource;
use App\Jobs\PollAtolReceiptJob;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Services\Payment\ReceiptSyncService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

#[Group('Orders', weight: 10)]
final class OrderReceiptController extends Controller
{
    public function __construct(private ReceiptSyncService $receiptSync) {}

    /**
     * Список фискальных чеков заказа (приходы и возвраты).
     *
     * Чеки отсортированы по `registered_at` и `id` по убыванию, массив пуст,
     * если чеков по заказу ещё нет. Поля `fiscal`, `qr`, `registered_at`,
     * `receiptUrl` могут быть null, пока чек не пробит. Перед ответом сервер
     * досинхронизирует незавершённые чеки (ЮKassa и АТОЛ).
     *
     * @response array{data: OrderReceiptResource[]}
     */
    #[ScrambleResponse(403, description: 'Заказ принадлежит другому пользователю', type: 'array{message: string}')]
    #[ScrambleResponse(404, description: 'Заказ не найден', type: 'array{message: string}')]
    public function index(Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        $this->ensureReceiptsFresh($order);

        $receipts = $order->receipts()
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->get();

        return OrderReceiptResource::collection($receipts);
    }

    /**
     * Если по успешной транзакции чека ещё нет в БД (вебхук опоздал/сбой) —
     * синхронно дотянуть его из ЮKassa перед ответом. Дополнительно — для
     * АТОЛ-чеков в статусе pending (колбэк ещё не пришёл) синхронно опросить
     * АТОЛ через `PollAtolReceiptJob::dispatchSync` (последняя попытка,
     * без самостоятельного повторного диспатча).
     */
    private function ensureReceiptsFresh(Order $order): void
    {
        $transactions = $order->paymentTransactions()
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->get();

        $syncedTransactionIds = $order->receipts()
            ->whereNotNull('payment_transaction_id')
            ->pluck('payment_transaction_id')
            ->all();

        $transactions
            ->reject(fn (PaymentTransaction $tx) => in_array($tx->id, $syncedTransactionIds, true))
            ->each(fn (PaymentTransaction $tx) => $this->receiptSync->syncForTransaction($tx));

        // Опрос АТОЛ — best-effort: сбой (timeout/ConnectionException при недоступности
        // АТОЛ) не должен ронять клиентский эндпоинт списка чеков. Логируем и отдаём
        // чек как pending — колбэк или следующий visit дотянут статус.
        $order->receipts()
            ->where('provider', ReceiptProvider::Atol->value)
            ->where('status', PaymentTransactionStatus::Pending->value)
            ->get()
            ->each(function (PaymentReceipt $receipt) use ($order) {
                try {
                    PollAtolReceiptJob::dispatchSync($receipt->id, 6);
                } catch (\Throwable $e) {
                    Log::warning('atol lazy poll failed', [
                        'order' => $order->id,
                        'receipt' => $receipt->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
