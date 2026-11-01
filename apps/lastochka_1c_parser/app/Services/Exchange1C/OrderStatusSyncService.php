<?php

namespace App\Services\Exchange1C;

use App\Models\Order;
use App\Models\ExchangeLog;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации статусов заказов из 1С
 *
 * Обрабатывает входящие файлы orders___*.xml от 1С
 * и обновляет статусы заказов на сайте
 */
class OrderStatusSyncService
{
    protected ?ExchangeLog $log = null;

    /**
     * Импорт статусов заказов из файла 1С
     *
     * @param string $fileName Путь к XML файлу
     * @param int|null $logId ID записи лога
     * @return int Количество обработанных заказов
     */
    public function importFromFile(string $fileName, ?int $logId = null): int
    {
        if ($logId) {
            $this->log = ExchangeLog::find($logId);
        }

        if (!file_exists($fileName)) {
            $this->logError("Файл не найден: {$fileName}");
            return 0;
        }

        $content = file_get_contents($fileName);
        if (empty($content)) {
            $this->logError("Файл пустой: {$fileName}");
            return 0;
        }

        try {
            $xml = simplexml_load_string($content);
            if ($xml === false) {
                $this->logError("Невалидный XML: {$fileName}");
                return 0;
            }

            $json = json_decode(json_encode($xml), true);

            // Префиксы штрихкодов могут приезжать секцией в файле статусов (контракт с 1С,
            // LASTOCHKI-JAMI-12013). Собственный try/catch — сбой разбора префиксов не должен
            // сорвать синхронизацию статусов заказов, ради которой вызван этот файл.
            try {
                app(PrefixImportService::class)->importFromArray(is_array($json) ? $json : []);
            } catch (\Throwable $e) {
                Log::channel('1c-exchange')->warning('OrderStatusSync: разбор секции префиксов штрихкодов не выполнен', [
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->importOrderStatuses($json);

        } catch (\Throwable $e) {
            $this->logError("Ошибка парсинга XML: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Обработка заказов из распарсенного JSON
     *
     * @param array $data Распарсенный JSON из XML файла 1С
     * @return int Количество обработанных заказов
     */
    public function importOrderStatuses(array $data): int
    {
        if (!isset($data['Документ'])) {
            Log::channel('1c-exchange')->warning('OrderStatusSync: Нет документов в данных от 1С');
            return 0;
        }

        $documents = $data['Документ'];

        // Если один документ - превращаем в массив
        if (isset($documents['Номер']) || isset($documents['Ид'])) {
            $documents = [$documents];
        }

        $processedCount = 0;

        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            try {
                if ($this->processDocument($document)) {
                    $processedCount++;
                }
            } catch (\Throwable $e) {
                Log::channel('1c-exchange')->error('OrderStatusSync: Ошибка обработки документа', [
                    'document_id' => $document['Ид'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($processedCount > 0) {
            Log::channel('1c-exchange')->info("OrderStatusSync: Обработано {$processedCount} заказов");
        }

        $this->log?->success($processedCount, "Обработано заказов: {$processedCount}");

        return $processedCount;
    }

    /**
     * Обработка одного документа заказа
     */
    protected function processDocument(array $document): bool
    {
        // Получаем идентификатор заказа
        $orderId = $this->extractOrderId($document);

        if (!$orderId) {
            Log::channel('1c-exchange')->debug('OrderStatusSync: Не удалось определить ID заказа', [
                'document' => array_keys($document),
            ]);
            return false;
        }

        // Находим заказ
        $order = $this->findOrder($orderId, $document);

        if (!$order) {
            Log::channel('1c-exchange')->debug('OrderStatusSync: Заказ не найден', [
                'order_id' => $orderId,
            ]);
            return false;
        }

        // Заказы «Лояльность» — служебные (создание карты): статус из 1С
        // не применяем и событие в магазин не публикуем.
        if ($order->source === Order::SOURCE_LOYALTY_CARD) {
            Log::channel('1c-exchange')->debug('OrderStatusSync: заказ «Лояльность», пропускаем', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        // Определяем новый статус
        $newStatus = $this->determineStatus($document);

        if (!$newStatus) {
            return false;
        }

        // Обновляем статус если он изменился
        if ($order->status !== $newStatus) {
            $oldStatus = $order->status;

            $order->status = $newStatus;
            $order->received_at = now();
            $order->one_c_response = $document;
            $order->save();

            Log::channel('1c-exchange')->info('OrderStatusSync: Статус заказа обновлен', [
                'order_id' => $order->id,
                'number' => $order->number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            $this->dispatchStatusChangedEvent($order, $oldStatus, $newStatus);

            return true;
        }

        return false;
    }

    /**
     * Извлечение ID заказа из документа
     */
    protected function extractOrderId(array $document): ?string
    {
        // Пробуем разные варианты идентификации

        // 1. По ID (order-123)
        if (isset($document['Ид'])) {
            $id = $document['Ид'];
            if (str_starts_with($id, 'order-')) {
                return substr($id, 6);
            }
            return $id;
        }

        // 2. По номеру
        if (isset($document['Номер'])) {
            return $document['Номер'];
        }

        return null;
    }

    /**
     * Поиск заказа по идентификатору
     */
    protected function findOrder(string $orderId, array $document): ?Order
    {
        // Пробуем найти по ID
        if (is_numeric($orderId)) {
            $order = Order::find((int) $orderId);
            if ($order) {
                return $order;
            }
        }

        // Пробуем найти по номеру
        $order = Order::where('number', $orderId)->first();
        if ($order) {
            return $order;
        }

        // Пробуем найти по external_id
        $order = Order::where('external_id', $orderId)->first();
        if ($order) {
            return $order;
        }

        // Пробуем найти по Ид документа
        if (isset($document['Ид'])) {
            $order = Order::where('external_id', $document['Ид'])->first();
            if ($order) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Определение нового статуса на основе данных от 1С
     */
    protected function determineStatus(array $document): ?string
    {
        $requisites = $this->extractRequisites($document);

        // 1. Приоритет: реквизит «Статуса заказа ИД» (или устаревший «Статус заказа»)
        $statusValue = $requisites['Статуса заказа ИД'] ?? $requisites['Статус заказа'] ?? null;

        if ($statusValue !== null) {
            if (!empty($statusValue) && in_array($statusValue, Order::STATUSES, true)) {
                return $statusValue;
            }

            if (!empty($statusValue)) {
                Log::channel('1c-exchange')->warning('OrderStatusSync: Неизвестный статус заказа от 1С', [
                    'value' => $statusValue,
                    'document_id' => $document['Ид'] ?? 'unknown',
                ]);
            }

            return null;
        }

        // 2. Fallback: Отменен
        if (isset($requisites['Отменен'])) {
            $value = strtolower($requisites['Отменен']);
            if ($value === 'true' || $value === '1') {
                return Order::STATUS_CANCELLED;
            }
        }

        // 3. Fallback: ПометкаУдаления
        if (isset($document['ПометкаУдаления'])) {
            $value = strtolower($document['ПометкаУдаления']);
            if ($value === 'true' || $value === '1') {
                return Order::STATUS_CANCELLED;
            }
        }

        // 4. Проведен=true без «Статус заказа» — не можем определить конкретный статус
        return null;
    }

    /**
     * Извлечение реквизитов из документа
     */
    protected function extractRequisites(array $document): array
    {
        $result = [];

        if (!isset($document['ЗначенияРеквизитов']['ЗначениеРеквизита'])) {
            return $result;
        }

        $values = $document['ЗначенияРеквизитов']['ЗначениеРеквизита'];

        // Если один элемент - превращаем в массив
        if (isset($values['Наименование'])) {
            $values = [$values];
        }

        foreach ($values as $requisite) {
            if (!is_array($requisite)) {
                continue;
            }

            $name = $requisite['Наименование'] ?? null;
            $value = $requisite['Значение'] ?? null;

            if ($name !== null) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Публикация события смены статуса в RabbitMQ outbox
     */
    protected function dispatchStatusChangedEvent(Order $order, string $oldStatus, string $newStatus): void
    {
        try {
            $statusRuToEn = array_flip(Order::SHOP_STATUS_MAP);

            CustomEventPublisher::dispatch('orders', $order, 'status_changed', [
                'old_status' => $statusRuToEn[$oldStatus] ?? $oldStatus,
                'new_status' => $statusRuToEn[$newStatus] ?? $newStatus,
            ]);

            Log::channel('1c-exchange')->info('OrderStatusSync: Событие order.status_changed отправлено в outbox', [
                'order_id' => $order->id,
                'shop_order_id' => $order->shop_order_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error('OrderStatusSync: Ошибка отправки события в outbox', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Логирование ошибки
     */
    protected function logError(string $message): void
    {
        Log::channel('1c-exchange')->error("OrderStatusSync: {$message}");
        $this->log?->error($message);
    }
}
