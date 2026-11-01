<?php

namespace App\Services\Exchange1C;

use App\Models\ExchangeLog;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\OrderSyncLog;
use App\Services\ExchangeDiagnosticsPublisher;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mavsan\LaProtocol\Interfaces\ExportOrdersSelf;
use Mavsan\LaProtocol\Interfaces\ExportSuccess;

/**
 * Handler для экспорта заказов в 1С
 *
 * Реализует интерфейс ExportOrdersSelf для формирования XML с заказами
 */
class OrderExportHandler implements ExportOrdersSelf, ExportSuccess
{
    protected ?ExchangeLog $log = null;
    protected array $exportedOrderIds = [];

    /**
     * Человекочитаемые метки способа получения заказа для выгрузки в 1С.
     * Ключ — значение orders.shipping_method из магазина
     * (courier/pickup/cdek/russian_post/yandex_delivery). «pickup» = самовывоз,
     * остальные — доставка (различаются перевозчиком).
     */
    protected const SHIPPING_METHOD_LABELS = [
        'pickup' => 'Самовывоз',
        'courier' => 'Доставка курьером',
        'cdek' => 'СДЭК',
        'russian_post' => 'Почта России',
        'yandex_delivery' => 'Яндекс Доставка',
    ];

    /**
     * Человекочитаемые метки способа оплаты для выгрузки в 1С.
     * Ключ — значение orders.payment_type из магазина (cash/card).
     */
    protected const PAYMENT_TYPE_LABELS = [
        'cash' => 'Наличные',
        'card' => 'Картой',
    ];

    /**
     * Формирование XML с заказами для 1С
     */
    public function getXML(): mixed
    {
        $this->log = ExchangeLog::start(ExchangeLog::TYPE_ORDERS_EXPORT);

        // Ретрай-страховка: если stepSuccess прошлой выгрузки не дошёл (обрыв сети),
        // подтверждение карты публикуем на следующем цикле обмена (идемпотентно
        // принимается storefront'ом). Собственный try/catch — сбой ретрая
        // (в т.ч. транзиентная ошибка БД в самой выборке) не должен ронять
        // экспортный цикл 1С.
        try {
            $this->publishLoyaltyConfirmations(
                Order::where('source', Order::SOURCE_LOYALTY_CARD)
                    ->whereNotNull('exported_at')
                    ->whereNull('confirmed_published_at')
                    ->where('exported_at', '<', now()->subMinutes(10))
                    ->get()
            );
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->warning('Ретрай подтверждений карт не выполнен', [
                'error' => $e->getMessage(),
            ]);
        }

        $sessionId = null;
        $handedOutAt = null;

        try {
            $shopId = null;
            try { $shopId = app('current_shop_id'); } catch (\Throwable $e) {}

            $encoding = $this->resolveXmlEncoding($shopId);
            $sessionId = $this->resolveExchangeSessionId();

            $orders = Order::forExport()
                ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
                // 1С повторяет query в той же сессии, пока не получит пустой ответ:
                // уже выданные этой сессии заказы второй раз не отдаём.
                ->when($sessionId, fn($q) => $q->where(fn($w) => $w
                    ->whereNull('export_session_id')
                    ->orWhere('export_session_id', '!=', $sessionId)))
                ->with('items')
                ->get();

            if ($orders->isEmpty()) {
                Log::channel('1c-exchange')->info('Нет заказов для экспорта');
                $this->log->success(0, 'Нет заказов для экспорта');
                $this->publishDiagnostics(0);
                return $this->buildEmptyXml($encoding);
            }

            $this->exportedOrderIds = $orders->pluck('id')->toArray();

            $handedOutAt = $this->markHandedOut($sessionId);

            $xml = $this->buildOrdersXml($orders, $encoding);

            $this->log->success($orders->count(), "Экспортировано заказов: {$orders->count()}");
            foreach ($this->exportedOrderIds as $exportedId) {
                $this->writeSyncLog($exportedId, OrderSyncLog::STATUS_SENT);
            }
            $this->publishDiagnostics($orders->count());

            Log::channel('1c-exchange')->info("Экспорт заказов: {$orders->count()} шт.");

            // Детальное логирование заказов
            foreach ($orders as $order) {
                Log::channel('1c-exchange')->info('Order export details', [
                    'order_id' => $order->id,
                    'number' => $order->number,
                    'total' => $order->total,
                    'items_count' => $order->items->count(),
                    'items' => $order->items->map(fn($item) => [
                        'id' => $item->id,
                        'external_id' => $item->external_id,
                        'name' => $item->name,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'total' => $item->total,
                    ])->toArray(),
                ]);
            }
            
            // Логируем сам XML
            Log::channel('1c-exchange')->debug('Generated XML for orders', [
                'xml_length' => strlen($xml),
                'xml' => $xml,
            ]);

            return $xml;
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error('Ошибка экспорта заказов: ' . $e->getMessage());
            // 1С получит пустой XML и пришлёт success — выданные заказы не должны
            // подтвердиться и потеряться, они уйдут в следующем цикле обмена.
            $this->revertHandOut($sessionId, $handedOutAt);
            $this->log?->error($e->getMessage());
            foreach ($this->exportedOrderIds as $exportedId) {
                $this->writeSyncLog($exportedId, OrderSyncLog::STATUS_ERROR, $e->getMessage());
            }
            $this->publishDiagnostics(0);

            return $this->buildEmptyXml($this->resolveXmlEncoding($shopId ?? null));
        }
    }

    /**
     * Вызывается после успешной выгрузки заказов в 1С
     */
    public function stepSuccess(): ?string
    {
        // 1С подтвердила загрузку: помечаем выгруженными заказы, выданные этой сессии.
        // mode=success приходит отдельным HTTP-запросом (новый инстанс хендлера),
        // поэтому выдачу ищем в БД по сессии обмена, а не в $this->exportedOrderIds.
        try {
            $sessionId = $this->resolveExchangeSessionId();

            if ($sessionId !== null) {
                foreach ($this->confirmHandedOut($sessionId) as $confirmedId) {
                    $this->writeSyncLog($confirmedId, OrderSyncLog::STATUS_SUCCESS);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->warning('Подтверждение выгрузки заказов на stepSuccess не выполнено', [
                'error' => $e->getMessage(),
            ]);
        }

        // Подтверждение карт: 1С подтвердила приём выгрузки — заказы «Лояльность» ушли.
        // Выборка из БД, НЕ из $this->exportedOrderIds: mode=success приходит отдельным
        // HTTP-запросом, и mavsan создаёт НОВЫЙ инстанс хендлера — in-memory список
        // здесь всегда пуст. Все выгруженные (exported_at) loyalty-заказы без
        // опубликованного confirmed законно подтверждать: 1С получила их выгрузкой
        // этого или предыдущего цикла.
        // Собственный try/catch: 'success' должен вернуться 1С ВСЕГДА (иначе она
        // не получит ack за уже принятую выгрузку); недошедшие подтверждения
        // доопубликует ретрай в getXML() на следующем цикле.
        try {
            $this->publishLoyaltyConfirmations(
                Order::where('source', Order::SOURCE_LOYALTY_CARD)
                    ->whereNotNull('exported_at')
                    ->whereNull('confirmed_published_at')
                    ->get()
            );
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->warning('Публикация подтверждений карт на stepSuccess не выполнена', [
                'error' => $e->getMessage(),
            ]);
        }

        return 'success';
    }

    /**
     * Идентификатор сессии обмена 1С: cookie, выданная на checkauth. 1С присылает её
     * во всех запросах цикла (query, повторный query, success). Разбор повторяет
     * mavsan CatalogController::catalogIn — не зависит от того, поднята ли сессия Laravel.
     */
    protected function resolveExchangeSessionId(): ?string
    {
        $request = request();
        $sessionName = config('session.cookie');

        $cookie = $request->header('cookie');
        if ($cookie && preg_match('/' . preg_quote($sessionName, '/') . '=([^;\s]+)/', $cookie, $matches)) {
            return mb_substr($matches[1], 0, 100);
        }

        foreach ([$request->cookies->get($sessionName), $request->header($sessionName)] as $value) {
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 100);
            }
        }

        return null;
    }

    /**
     * Отметить выдачу заказов из $this->exportedOrderIds.
     *
     * При известной сессии обмена exported_at не трогаем — его поставит stepSuccess.
     * Без сессии повторный query нельзя отличить от нового цикла, поэтому помечаем
     * сразу (иначе 1С получала бы те же заказы бесконечно).
     *
     * @return \Illuminate\Support\Carbon|null время пометки exported_at (только без сессии)
     */
    protected function markHandedOut(?string $sessionId): ?\Illuminate\Support\Carbon
    {
        if ($sessionId === null) {
            $markedAt = now();
            Order::whereIn('id', $this->exportedOrderIds)->update(['exported_at' => $markedAt]);

            Log::channel('1c-exchange')->warning('Сессия обмена не определена, заказы помечены экспортированными без подтверждения 1С', [
                'order_ids' => $this->exportedOrderIds,
            ]);

            return $markedAt;
        }

        Order::whereIn('id', $this->exportedOrderIds)->update(['export_session_id' => $sessionId]);

        Log::channel('1c-exchange')->info('Заказы выданы 1С, ждём подтверждения (mode=success)', [
            'order_ids' => $this->exportedOrderIds,
        ]);

        return null;
    }

    /**
     * Откатить выдачу, если XML не был сформирован и 1С получит пустой ответ.
     */
    protected function revertHandOut(?string $sessionId, ?\Illuminate\Support\Carbon $markedAt): void
    {
        if (empty($this->exportedOrderIds)) {
            return;
        }

        try {
            $query = Order::whereIn('id', $this->exportedOrderIds);

            if ($sessionId !== null) {
                $query->where('export_session_id', $sessionId)->update(['export_session_id' => null]);
            } elseif ($markedAt !== null) {
                $query->where('exported_at', $markedAt)->update(['exported_at' => null]);
            }
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->warning('Не удалось откатить выдачу заказов после ошибки экспорта', [
                'order_ids' => $this->exportedOrderIds,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Пометить выгруженными заказы, выданные сессии обмена. Условие по сессии в самом
     * UPDATE: если заказ успел смениться (статус/состав сбросили выдачу), устаревшую
     * версию не подтверждаем — новая уйдёт следующим query.
     *
     * @return int[] id подтверждённых заказов
     */
    protected function confirmHandedOut(string $sessionId): array
    {
        $confirmedAt = now();
        $confirmed = [];

        foreach (Order::where('export_session_id', $sessionId)->pluck('id') as $orderId) {
            $affected = Order::whereKey($orderId)
                ->where('export_session_id', $sessionId)
                ->update(['exported_at' => $confirmedAt, 'export_session_id' => null]);

            if ($affected > 0) {
                $confirmed[] = $orderId;
            }
        }

        if ($confirmed) {
            Log::channel('1c-exchange')->info('1С подтвердила загрузку, заказы помечены как экспортированные', [
                'order_ids' => $confirmed,
            ]);
        }

        return $confirmed;
    }

    protected function publishDiagnostics(int $exportedCount): void
    {
        if (!$this->log) {
            return;
        }

        $this->log->refresh();
        $pendingCount = Order::forExport()->count();

        ExchangeDiagnosticsPublisher::publish($this->log, [
            'exported' => $exportedCount,
            'pending' => $pendingCount,
        ]);
    }

    /**
     * Формирование XML с заказами
     */
    protected function buildOrdersXml($orders, string $encoding = 'UTF-8'): string
    {
        $dom = new \DOMDocument('1.0', $encoding);
        $dom->formatOutput = true;

        $root = $dom->createElement('КоммерческаяИнформация');
        $root->setAttribute('ВерсияСхемы', '2.03');
        $root->setAttribute('ДатаФормирования', now()->format('Y-m-d\TH:i:s'));
        $dom->appendChild($root);

        foreach ($orders as $order) {
            $document = $this->buildOrderDocument($dom, $order);
            $root->appendChild($document);
        }

        return $dom->saveXML();
    }

    /**
     * Формирование документа заказа
     */
    protected function buildOrderDocument(\DOMDocument $dom, Order $order): \DOMElement
    {
        $document = $dom->createElement('Документ');

        // Основные данные заказа
        $this->appendElement($dom, $document, 'Ид', 'order-' . $order->id);
        $this->appendElement($dom, $document, 'Номер', $order->number);
        $this->appendElement($dom, $document, 'Дата', $order->created_at->format('Y-m-d'));
        $this->appendElement($dom, $document, 'Время', $order->created_at->format('H:i:s'));
        $this->appendElement($dom, $document, 'ХозОперация', 'Заказ товара');
        $this->appendElement($dom, $document, 'Роль', 'Продавец');
        $this->appendElement($dom, $document, 'Валюта', 'RUB');
        $this->appendElement($dom, $document, 'Курс', '1');
        $this->appendElement($dom, $document, 'Сумма', number_format($order->total, 2, '.', ''));

        // Скидка по заказу НЕ выгружается отдельным узлом <Скидки>: по согласованию
        // с 1С клиента цены позиций уже приходят со скидкой (net, см. buildOrderItems),
        // итог документа <Сумма> = order.total = Σ позиций(net) + доставка.

        // Комментарий с телефоном напрямую в документе
        $commentText = $order->customer_phone ? 'Тел: ' . $order->customer_phone : '';
        if ($order->comment) {
            $commentText .= ($commentText ? '. ' : '') . $order->comment;
        }
        if ($commentText) {
            $this->appendElement($dom, $document, 'Комментарий', $commentText);
        }

        // Статус заказа напрямую в документе (не в реквизитах)
        $this->appendElement($dom, $document, 'СтатусЗаказа', $order->status);

        // Контрагент (покупатель)
        $document->appendChild($this->buildContragent($dom, $order));

        // Товары
        $document->appendChild($this->buildOrderItems($dom, $order));

        // Дополнительные реквизиты
        $document->appendChild($this->buildRequisites($dom, $order));

        return $document;
    }

    /**
     * Формирование контрагента
     */
    protected function buildContragent(\DOMDocument $dom, Order $order): \DOMElement
    {
        $contragents = $dom->createElement('Контрагенты');
        $contragent = $dom->createElement('Контрагент');

        $this->appendElement($dom, $contragent, 'Ид', 'customer-' . $order->id);
        $this->appendElement($dom, $contragent, 'Наименование', $order->customer_name);
        $this->appendElement($dom, $contragent, 'Роль', 'Покупатель');
        $this->appendElement($dom, $contragent, 'ПолноеНаименование', $order->customer_name);
        
        // Телефон напрямую в контрагенте (для Штрих-м) — в формате «+7…».
        if ($order->customer_phone) {
            $this->appendElement($dom, $contragent, 'Телефон', $this->formatPhoneFor1C($order->customer_phone));
        }

        // Контакты
        $contacts = $dom->createElement('Контакты');

        if ($order->customer_phone) {
            $phoneContact = $dom->createElement('Контакт');
            $this->appendElement($dom, $phoneContact, 'Тип', 'ТелефонРабочий');
            $this->appendElement($dom, $phoneContact, 'Значение', $this->formatPhoneFor1C($order->customer_phone));
            $contacts->appendChild($phoneContact);
        }

        if ($order->customer_email) {
            $emailContact = $dom->createElement('Контакт');
            $this->appendElement($dom, $emailContact, 'Тип', 'Почта');
            $this->appendElement($dom, $emailContact, 'Значение', $order->customer_email);
            $contacts->appendChild($emailContact);
        }

        $contragent->appendChild($contacts);

        // Адрес
        if ($order->delivery_address) {
            $address = $dom->createElement('АдресРегистрации');
            $this->appendElement($dom, $address, 'Представление', $order->delivery_address);
            $contragent->appendChild($address);
        }

        $contragents->appendChild($contragent);

        return $contragents;
    }

    /**
     * Формирование товаров заказа
     */
    protected function buildOrderItems(\DOMDocument $dom, Order $order): \DOMElement
    {
        $items = $dom->createElement('Товары');

        // Скидка заказа уже учтена в ценах/суммах позиций (вариант, согласованный
        // с 1С клиента): на каждую товарную позицию приходится её доля скидки.
        $netLineTotals = $this->allocateDiscountedLineTotals($order);

        foreach ($order->items->values() as $idx => $item) {
            $lineTotal = $netLineTotals[$idx] ?? (float) $item->total;
            $qty = (float) $item->quantity;
            // Цена за единицу пересчитывается из net-суммы строки. При неровной
            // скидке Цена×Кол может отличаться от Суммы на копейку (округление) —
            // авторитетна Сумма строки, Σ строк = order.total − доставка.
            $unitPrice = $qty > 0 ? round($lineTotal / $qty, 2) : $lineTotal;

            Log::channel('1c-exchange')->debug('Building order item XML', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'external_id' => $item->external_id,
                'name' => $item->name,
                'name_empty' => empty($item->name),
                'price_gross' => $item->price,
                'total_gross' => $item->total,
                'unit_price_net' => $unitPrice,
                'line_total_net' => $lineTotal,
            ]);

            $product = $dom->createElement('Товар');

            $this->appendElement($dom, $product, 'Ид', $item->external_id);
            $this->appendElement($dom, $product, 'Наименование', $item->name ?: 'Без названия');

            // Базовая единица — всегда «шт» (796): номенклатура в 1С клиента
            // заведена в штуках, и выгрузка весовых в «кг» (166) ломала приём
            // заказов на стороне 1С (документ не проводился). Откат к рабочему
            // формату — см. learnings 2026-06-25.
            $unit = $dom->createElement('БазоваяЕдиница', 'шт');
            $unit->setAttribute('Код', '796');
            $product->appendChild($unit);

            $this->appendElement($dom, $product, 'ЦенаЗаЕдиницу', number_format($unitPrice, 2, '.', ''));
            $this->appendElement($dom, $product, 'Количество', (string) $item->quantity);
            $this->appendElement($dom, $product, 'Сумма', number_format($lineTotal, 2, '.', ''));

            $items->appendChild($product);
        }

        // Доставка отдельной позицией — иначе сумма позиций не сходится с суммой
        // документа (которая включает стоимость доставки). Добавляем только если есть.
        if ((float) $order->delivery_cost > 0) {
            $delivery = $dom->createElement('Товар');

            $this->appendElement($dom, $delivery, 'Ид', (string) config('exchange1c.delivery_nomenclature_id', 'delivery'));
            $this->appendElement($dom, $delivery, 'Наименование', (string) config('exchange1c.delivery_nomenclature_name', 'Доставка'));

            $unit = $dom->createElement('БазоваяЕдиница', 'шт');
            $unit->setAttribute('Код', '796');
            $delivery->appendChild($unit);

            $this->appendElement($dom, $delivery, 'ЦенаЗаЕдиницу', number_format($order->delivery_cost, 2, '.', ''));
            $this->appendElement($dom, $delivery, 'Количество', '1');
            $this->appendElement($dom, $delivery, 'Сумма', number_format($order->delivery_cost, 2, '.', ''));

            $items->appendChild($delivery);
        }

        return $items;
    }

    /**
     * Распределение скидки заказа по товарным позициям (цены/суммы — со скидкой, net).
     *
     * По согласованию с 1С клиента скидка не выгружается отдельным узлом <Скидки>:
     * вместо этого цены позиций приходят уже уменьшенными. Скидка order.discount
     * раскидывается на товарные позиции пропорционально их gross-сумме (доставка
     * скидке не подлежит и здесь не участвует). Считаем в копейках; остаток
     * округления доносим на ПОСЛЕДНЮЮ позицию, чтобы Σ net == Σ gross − discount
     * (а с доставкой = order.total) без копеечного дрейфа.
     *
     * @return array<int, float> индекс позиции (по items->values()) => Сумма строки со скидкой
     */
    protected function allocateDiscountedLineTotals(Order $order): array
    {
        $grossCents = $order->items->values()
            ->map(fn($item) => (int) round((float) $item->total * 100))
            ->all();

        $netCents = $grossCents;
        $discountCents = (int) round((float) $order->discount * 100);
        $totalGrossCents = array_sum($grossCents);

        if ($discountCents > 0 && $totalGrossCents > 0) {
            $last = count($grossCents) - 1;
            $allocated = 0;
            foreach ($grossCents as $idx => $gc) {
                if ($idx < $last) {
                    $share = (int) round($discountCents * $gc / $totalGrossCents);
                    $netCents[$idx] = $gc - $share;
                    $allocated += $share;
                } else {
                    // Остаток скидки целиком на последнюю позицию — гасит дрейф округления.
                    $netCents[$idx] = $gc - ($discountCents - $allocated);
                }
            }
        }

        return array_map(fn($cents) => round($cents / 100, 2), $netCents);
    }

    /**
     * Базовая единица измерения позиции для выгрузки в 1С.
     *
     * @return array{0: string, 1: string} [Наименование, Код ОКЕИ]
     */
    protected function resolveBaseUnit(?string $code): array
    {
        return match ($code) {
            '166' => ['кг', '166'],
            default => ['шт', '796'],
        };
    }

    /**
     * Формирование дополнительных реквизитов
     */
    protected function buildRequisites(\DOMDocument $dom, Order $order): \DOMElement
    {
        $requisites = $dom->createElement('ЗначенияРеквизитов');

        // Комментарий с телефоном
        $commentParts = [];
        if ($order->customer_phone) {
            $commentParts[] = 'Тел: ' . $order->customer_phone;
        }
        if ($order->comment) {
            $commentParts[] = $order->comment;
        }
        
        if (!empty($commentParts)) {
            $requisite = $dom->createElement('ЗначениеРеквизита');
            $this->appendElement($dom, $requisite, 'Наименование', 'Комментарий');
            $this->appendElement($dom, $requisite, 'Значение', implode('. ', $commentParts));
            $requisites->appendChild($requisite);
        }

        // Статус заказа ИД
        $statusRequisite = $dom->createElement('ЗначениеРеквизита');
        $this->appendElement($dom, $statusRequisite, 'Наименование', 'Статуса заказа ИД');
        $this->appendElement($dom, $statusRequisite, 'Значение', $order->status);
        $requisites->appendChild($statusRequisite);

        // Телефон клиента отдельным реквизитом — чтобы 1С читала чистое поле,
        // а не парсила номер из текста «Комментарий». Номер выгружаем в формате «+7…».
        if ($order->customer_phone) {
            $phoneRequisite = $dom->createElement('ЗначениеРеквизита');
            $this->appendElement($dom, $phoneRequisite, 'Наименование', 'Телефон');
            $this->appendElement($dom, $phoneRequisite, 'Значение', $this->formatPhoneFor1C($order->customer_phone));
            $requisites->appendChild($phoneRequisite);
        }

        // Адрес доставки — реквизит ДОКУМЕНТА. Узел <АдресРегистрации> контрагента
        // 1С кладёт в карточку клиента, а в шапку «Заказа покупателя» тянет именно
        // реквизит «Адрес доставки» (имя согласовано с 1С-ником клиента, 2026-09-07).
        if ($order->delivery_address) {
            $addressRequisite = $dom->createElement('ЗначениеРеквизита');
            $this->appendElement($dom, $addressRequisite, 'Наименование', 'Адрес доставки');
            $this->appendElement($dom, $addressRequisite, 'Значение', $order->delivery_address);
            $requisites->appendChild($addressRequisite);
        }

        // Способ доставки (доставка/самовывоз) — выгружаем только если известен.
        $shippingLabel = $this->resolveShippingMethodLabel($order->shipping_method);
        if ($shippingLabel !== null) {
            $shippingRequisite = $dom->createElement('ЗначениеРеквизита');
            $this->appendElement($dom, $shippingRequisite, 'Наименование', 'Способ доставки');
            $this->appendElement($dom, $shippingRequisite, 'Значение', $shippingLabel);
            $requisites->appendChild($shippingRequisite);
        }

        // Способ оплаты — выгружаем только если известен.
        $paymentLabel = $this->resolvePaymentTypeLabel($order->payment_type);
        if ($paymentLabel !== null) {
            $paymentRequisite = $dom->createElement('ЗначениеРеквизита');
            $this->appendElement($dom, $paymentRequisite, 'Наименование', 'Способ оплаты');
            $this->appendElement($dom, $paymentRequisite, 'Значение', $paymentLabel);
            $requisites->appendChild($paymentRequisite);
        }

        // Реквизиты системы лояльности (LASTOCHKI-JAMI-12013): все четыре присутствуют
        // в каждом документе (критерий приёмки) — нули и «Нет» выгружаются явно.
        $this->appendRequisite($dom, $requisites, 'Начислено баллов', (string) (int) $order->bonus_earned);
        $this->appendRequisite($dom, $requisites, 'Списано баллов', (string) (int) $order->bonus_spent);
        $this->appendRequisite($dom, $requisites, 'Признак наличия карты', $order->has_card ? 'Да' : 'Нет');

        // «Номер карты» — у обычных заказов только при подтверждённой карте (has_card),
        // а у заказа СОЗДАНИЯ карты (source=loyalty_card) — всегда: там признак = Нет
        // («карты ещё нет»), но номер обязателен — именно его 1С должна завести.
        if (($order->has_card || $order->source === Order::SOURCE_LOYALTY_CARD) && $order->card_number) {
            $this->appendRequisite($dom, $requisites, 'Номер карты', (string) $order->card_number);
        }

        // Заказ «Лояльность» несёт ИД лояльности — 1С заводит карту контрагенту по нему.
        if ($order->source === Order::SOURCE_LOYALTY_CARD && $order->loyalty_id) {
            $this->appendRequisite($dom, $requisites, 'ИД лояльности', (string) $order->loyalty_id);
        }

        return $requisites;
    }

    /**
     * Метка способа получения для 1С по коду из магазина.
     * Неизвестный непустой код трактуем как доставку (а не самовывоз),
     * чтобы не пометить курьерский заказ самовывозом по ошибке.
     */
    protected function resolveShippingMethodLabel(?string $shippingMethod): ?string
    {
        if ($shippingMethod === null || trim($shippingMethod) === '') {
            return null;
        }

        return self::SHIPPING_METHOD_LABELS[$shippingMethod] ?? 'Доставка';
    }

    /**
     * Метка способа оплаты для 1С по коду из магазина.
     * Неизвестный непустой код передаём как есть (чтобы не терять информацию).
     */
    protected function resolvePaymentTypeLabel(?string $paymentType): ?string
    {
        if ($paymentType === null || trim($paymentType) === '') {
            return null;
        }

        return self::PAYMENT_TYPE_LABELS[$paymentType] ?? $paymentType;
    }

    /**
     * Пустой XML (когда нет заказов)
     */
    protected function buildEmptyXml(string $encoding = 'UTF-8'): string
    {
        $dom = new \DOMDocument('1.0', $encoding);
        $dom->formatOutput = true;

        $root = $dom->createElement('КоммерческаяИнформация');
        $root->setAttribute('ВерсияСхемы', '2.03');
        $root->setAttribute('ДатаФормирования', now()->format('Y-m-d\TH:i:s'));
        $dom->appendChild($root);

        return $dom->saveXML();
    }

    /**
     * Определение кодировки XML-ответа.
     * Per-shop override через credentials.xml_encoding в integration_configs,
     * иначе глобальный default из config('exchange1c.xml_encoding').
     */
    protected function resolveXmlEncoding(?int $shopId): string
    {
        if ($shopId) {
            try {
                $config = IntegrationConfig::query()
                    ->where('integration_type', '1c')
                    ->where('shop_id', $shopId)
                    ->where('is_active', true)
                    ->first();

                if ($config && is_array($config->credentials)) {
                    $encoding = $config->credentials['xml_encoding'] ?? null;
                    if (is_string($encoding) && $encoding !== '') {
                        return $encoding;
                    }
                }
            } catch (\Throwable $e) {
                // fall back to global default
            }
        }

        return (string) config('exchange1c.xml_encoding', 'UTF-8');
    }

    /**
     * Телефон для машинных полей выгрузки в 1С — в формате «+7…».
     *
     * Нормализуем номер к виду «+<цифры>»: выкидываем все нецифровые символы
     * (пробелы, скобки, дефисы, существующие «+») и добавляем один ведущий «+».
     * Применяется к структурным полям (<Телефон> контрагента, Контакт
     * «ТелефонРабочий», реквизит «Телефон»).
     */
    protected function formatPhoneFor1C(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? '+' . $digits : '';
    }

    /**
     * Записать строку журнала синхронизации, не роняя выгрузку при сбое логирования.
     */
    protected function writeSyncLog(int $orderId, string $status, ?string $message = null): void
    {
        try {
            OrderSyncLog::createForOrder($orderId, $status, $message);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->warning('Не удалось записать журнал синхронизации заказа', [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Публикует loyalty_card.confirmed для выгруженных заказов «Лояльность»:
     * факт подтверждения карты = заказ ушёл в 1С (LASTOCHKI-JAMI-12013).
     * Прямой EventOutbox — нужен фиксированный routing key (контракт со storefront).
     *
     * @param  iterable<Order>  $orders
     */
    protected function publishLoyaltyConfirmations(iterable $orders): void
    {
        foreach ($orders as $order) {
            try {
                // Атомарно (паттерн BonusOperationImportService): событие и отметка
                // confirmed_published_at — вместе, иначе create-успех/update-фейл
                // даст дубль confirmed на следующем ретрае.
                DB::transaction(function () use ($order) {
                    $eventId = (string) Str::uuid();

                    EventOutbox::create([
                        'entity_type' => 'loyalty_cards',
                        'entity_id' => $order->id,
                        'event_type' => 'confirmed',
                        'event_id' => $eventId,
                        'routing_key' => 'loyalty_card.confirmed',
                        'payload' => [
                            'event_id' => $eventId,
                            'event_type' => 'confirmed',
                            'event_source' => config('rabbitmq-events.source'),
                            'loyalty_id' => $order->loyalty_id,
                            'card_number' => $order->card_number,
                        ],
                    ]);

                    $order->update(['confirmed_published_at' => now()]);
                });
            } catch (\Throwable $e) {
                Log::channel('1c-exchange')->warning('Не удалось опубликовать loyalty_card.confirmed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Хелпер: добавить именованный реквизит в <ЗначенияРеквизитов>
     */
    protected function appendRequisite(\DOMDocument $dom, \DOMElement $requisites, string $name, string $value): void
    {
        $requisite = $dom->createElement('ЗначениеРеквизита');
        $this->appendElement($dom, $requisite, 'Наименование', $name);
        $this->appendElement($dom, $requisite, 'Значение', $value);
        $requisites->appendChild($requisite);
    }

    /**
     * Хелпер: добавить элемент
     */
    protected function appendElement(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name, htmlspecialchars($value, ENT_XML1));
        $parent->appendChild($element);
    }
}
