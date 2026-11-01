<?php

namespace App\Services\Converters;

use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Support\Facades\Log;

class OrderConverter extends BaseConverter
{
    /**
     * @var array
     */
    protected $statusMap;

    /**
     * @var array
     */
    protected $inboundStatusMap;

    protected $deliveryMethodMap = [
        'courier' => 'courier',
        'pickup' => 'pickup',
        'cdek' => 'cdek',
        'russian-post' => 'russian_post',
        'self-delivery' => 'pickup',
        // Add more mappings as needed
    ];

    /**
     * @var RetailCRMService|null
     */
    protected ?RetailCRMService $retailCrmService = null;

    /**
     * @param array $config
     * @param RetailCRMService|null $retailCrmService
     */
    public function __construct(array $config = null, ?RetailCRMService $retailCrmService = null)
    {
        $this->retailCrmService = $retailCrmService;
        $this->statusMap = $config['order_statuses'] ?? [];
        $this->inboundStatusMap = $config['order_statuses_inbound'] ?? [];
        if (empty($this->inboundStatusMap) && !empty($this->statusMap)) {
            $this->inboundStatusMap = array_flip($this->statusMap);
        }

        if (isset($config['delivery_methods'])) {
            $this->deliveryMethodMap = array_merge($this->deliveryMethodMap, $config['delivery_methods']);
        }

        $this->retailcrmSource = $config['source'] ?? config('retailcrm.source', 'retailcrm');
    }

    /**
     * Конвертирует данные из формата брокера сообщений в формат RetailCRM.
     *
     * @param array $data
     * @return array
     */
    public function toRetailCRM(array $data): array
    {
        $externalId = $data['externalId'] ?? $data['id'] ?? null;
        $countryIso = 'RU';

        $result = [
            'externalId' => $externalId,
            'number' => $externalId ?? $data['id'] ?? null,
            'site' => $this->resolveShopCode($data['shop_code'] ?? null),
            'orderMethod' => 'shopping-cart',
            'firstName' => $data['client_info']['name'] ?? '',
            'lastName' => $data['client_info']['surname'] ?? '',
            'patronymic' => $data['client_info']['patronymic'] ?? '',
            'phone' => $data['client_info']['phone'] ?? '',
            'email' => $data['client_info']['email'] ?? null,
            'customerComment' => $data['comment'] ?? '',
            'managerComment' => $data['manager_comment'] ?? '',
            'countryIso' => $countryIso,
        ];

        if (isset($data['status'])) {
            $result['status'] = $this->statusMap[$data['status']] ?? ($data['status'] ?? null);
        }

        if (array_key_exists('is_paid', $data)) {
            $result['payments'][] = [
                'type' => 'cash',
                'status' => $data['is_paid'] ? 'paid' : 'not-paid',
                'amount' => $data['grand_total'] ?? 0,
            ];
        }

        if (isset($data['items_subtotal'])) {
            $result['summ'] = $data['items_subtotal'];
        }

        if (isset($data['grand_total'])) {
            $result['totalSumm'] = $data['grand_total'];
        }

        if (isset($data['discount_total'])) {
            $result['discountManualAmount'] = $data['discount_total'];
        }

        $customerData = [];
        if (!empty($data['client_id'])) {
            $customerData['externalId'] = $data['client_id'];
        }

        if (!empty($data['client_info'])) {
            $info = array_filter([
                'firstName' => $data['client_info']['name'] ?? null,
                'lastName' => $data['client_info']['surname'] ?? null,
                'patronymic' => $data['client_info']['patronymic'] ?? null,
                'phones' => !empty($data['client_info']['phone']) ? [
                    ['number' => $data['client_info']['phone']]
                ] : null,
                'email' => $data['client_info']['email'] ?? null,
            ]);
            $customerData = array_merge($customerData, $info);
        }

        if (!empty($customerData)) {
            $result['customer'] = $customerData;
        }

        // // Добавляем товары заказа
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $crmItem = [
                    'offer' => [
                        'id' => $item['id'] ?? null,
                    ],
                    'quantity' => $item['quantity'] ?? 1,
                    'initialPrice' => $item['unit_price'] ?? $item['unit_price_effective'] ?? 0,
                    'discountTotal' => $item['line_discount_total'] ?? 0,
                    'createdAt' => isset($item['created_at']) ? date('Y-m-d H:i:s', strtotime($item['created_at'])) : null,
                ];

                if (!empty($item['externalId'])) {
                    $crmItem['externalId'] = $item['externalId'];
                } elseif (!empty($item['id'])) {
                    $crmItem['externalId'] = (string)$item['id'];
                }

                if (!empty($item['product_name'])) {
                    $crmItem['displayName'] = $item['product_name'];
                }

                $result['items'][] = $crmItem;
            }
        }

        // Добавляем доставку
        if (!empty($data['shipping_method'])) {
            $result['delivery'] = [
                'code' => $data['shipping_method'],
                'cost' => $data['shipping_total'] ?? 0,
                'address' => [],
            ];

            // Добавляем адрес доставки, если он есть
            if (!empty($data['address'])) {
                $result['delivery']['address'] = [
                    'text' => $data['address']['address'],
                ];

                if (!empty($data['address']['entrance'])) {
                    $result['delivery']['address']['building'] = $data['address']['entrance'];
                }

                if (!empty($data['address']['flat'])) {
                    $result['delivery']['address']['flat'] = $data['address']['flat'];
                }

                if (!empty($data['address']['floor'])) {
                    $result['delivery']['address']['floor'] = $data['address']['floor'];
                }

                if (!empty($data['address']['intercom'])) {
                    $result['delivery']['address']['intercom'] = $data['address']['intercom'];
                }

                if (!empty($data['address']['block'])) {
                    $result['delivery']['address']['entrance'] = $data['address']['block'];
                }

                if (!empty($data['address']['city'])) {
                    $result['delivery']['address']['city'] = $data['address']['city'];
                }

                if (!empty($data['address']['coordinates']['latitude']) && !empty($data['address']['coordinates']['longitude'])) {
                    $result['delivery']['address']['coordinates'] = [
                        'latitude' => $data['address']['coordinates']['latitude'],
                        'longitude' => $data['address']['coordinates']['longitude'],
                    ];
                }
            }

            // Добавляем временной слот доставки, если он есть
            if (!empty($data['slot_start_date']) && !empty($data['slot_end_date'])) {
                $result['delivery']['date'] = date('Y-m-d', strtotime($data['slot_start_date']));
                $result['delivery']['time'] = [
                    'from' => date('H:i', strtotime($data['slot_start_date'])),
                    'to' => date('H:i', strtotime($data['slot_end_date'])),
                ];
            }

            if (!empty($data['tracking_number'])) {
                $result['delivery']['trackNumber'] = $data['tracking_number'];
            }
        }

        \Log::info('OrderConverter::toRetailCRM', ['result' => $result]);

        return $result;
    }

    /**
     * Конвертирует данные из формата RetailCRM в формат брокера сообщений.
     *
     * @param array $data
     * @param \App\Models\System\EventSyncMetadata|null $metadata
     * @return array
     */
    public function fromRetailCRM(array $data, $metadata = null): array
    {
        // Используем карту входящих статусов
        // $invertedStatusMap = array_flip($this->statusMap); // Старая логика

        // Определение типа оплаты
        $paymentType = 'cash';
        if (!empty($data['payments'])) {
            foreach ($data['payments'] as $payment) {
                // Список типов оплат, которые считаются безналичными
                if (in_array($payment['type'], ['bank-card', 'e-money', 'credit', 'invoice'])) {
                    $paymentType = 'card';
                    break;
                }
            }
        }

        // Determine source and ID from metadata if available
        $id = (string) ($data['id'] ?? uniqid());
        $source = $this->retailcrmSource;

        if ($metadata) {
            $source = $metadata->source;
            $id = $metadata->external_id;
        } elseif (!empty($data['externalId'])) {
            // Если metadata не передан (вызов из WebhookController),
            // используем externalId из CRM-данных вместо CRM internal ID.
            // externalId в CRM — это оригинальный ID заказа из admin.
            $id = (string) $data['externalId'];
        }

        // Извлекаем shop_code один раз для использования в поиске метаданных
        $orderShopCode = $this->extractShopCode($data);

        $result = [
            'id' => $id,
            'externalId' => $data['externalId'] ?? ($data['id'] ?? null),
            'shop_code' => $orderShopCode,
            'source' => $source,
            'status' => $this->inboundStatusMap[$data['status']] ?? $data['status'],
            'payment_type' => $paymentType,
            'items_subtotal' => $data['summ'] ?? 0,
            'discount_total' => $data['discountManualAmount'] ?? 0,
            'items_total_after_discount' => ($data['summ'] ?? 0) - ($data['discountManualAmount'] ?? 0),
            'shipping_total' => $data['delivery']['cost'] ?? 0,
            'grand_total' => $data['totalSumm'] ?? 0,
            'is_paid' => isset($data['payments'][0]['status']) && $data['payments'][0]['status'] === 'paid',
            'items' => [],
            'client_info' => [
                'name' => $data['firstName'] ?? '',
                'surname' => $data['lastName'] ?? '',
                'patronymic' => $data['patronymic'] ?? '',
                'phone' => $data['phone'] ?? '',
                'email' => $data['email'] ?? '',
            ],
            'client_comment' => $data['customerComment'] ?? '',
        ];

        // Добавляем ID клиента
        // Ищем оригинальный source клиента через метаданные по entity_id (ID в RetailCRM)
        if (!empty($data['customer']['id'])) {
            $customerRetailId = (string) $data['customer']['id'];

            // Пытаемся найти оригинальный source и external_id клиента
            $customerResolved = $this->resolveSourceAndExternalId('customer', $customerRetailId, $orderShopCode);
            $result['client_id'] = $customerResolved['external_id'];
            $result['client_source'] = $customerResolved['source'];

            // Если у клиента в CRM есть внешний ID, добавляем для справки
            if (!empty($data['customer']['externalId'])) {
                $result['client_external_id'] = $data['customer']['externalId'];
            }
        } elseif (!empty($data['customer']['externalId'])) {
            // Fallback если почему-то нет ID (что странно для CRM)
            $result['client_id'] = $data['customer']['externalId'];
            $result['client_source'] = $this->retailcrmSource;
        } else {
            // Критический fallback, чтобы не ломать контракт
            $result['client_id'] = 'guest_' . ($data['id'] ?? uniqid());
            $result['client_source'] = $this->retailcrmSource;
        }

        // Добавляем товары заказа
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $retailOfferId = !empty($item['offer']['id']) ? (string) $item['offer']['id'] : null;
                $productSource = $this->retailcrmSource;
                $productId = null;

                // 1. Если у offer есть externalId — используем его напрямую
                if (!empty($item['offer']['externalId'])) {
                    $productId = $item['offer']['externalId'];
                    // Пробуем найти source через metadata
                    $resolved = $this->resolveSourceAndExternalId('product', $productId, $orderShopCode);
                    if ($resolved['source'] !== $this->retailcrmSource) {
                        $productSource = $resolved['source'];
                    }
                }
                // 2. Если externalId нет — нужно получить product.externalId через API
                elseif ($retailOfferId && $this->retailCrmService) {
                    $productExternalId = $this->getProductExternalIdByOfferId($retailOfferId);
                    if ($productExternalId) {
                        $productId = $productExternalId;
                        // Ищем source через metadata по product.externalId
                        $metadata = $this->findMetadataByExternalId('product', $productExternalId, $orderShopCode);
                        if ($metadata) {
                            $productSource = $metadata->source;
                        }
                    }
                }

                // 3. Fallback: если не удалось определить — используем offer.id
                if ($productId === null) {
                    $productId = $retailOfferId ?? $item['offer']['xmlId'] ?? 'unknown_' . uniqid();
                }

                // Определяем source и external_id для позиции заказа (order_item)
                $orderItemSource = $this->retailcrmSource;
                $orderItemExternalId = (string) ($item['id'] ?? uniqid());

                if (!empty($item['id'])) {
                    $itemResolved = $this->resolveSourceAndExternalId('order_item', (string) $item['id'], $orderShopCode);
                    $orderItemSource = $itemResolved['source'];
                    $orderItemExternalId = $itemResolved['external_id'];
                }

                $result['items'][] = [
                    'id' => $orderItemExternalId,
                    'source' => $orderItemSource,
                    'product_id' => (string) $productId,
                    'product_source' => $productSource,
                    'product_name' => $item['offer']['displayName'] ?? $item['offer']['name'] ?? 'Unknown Product',
                    'unit_price' => $item['initialPrice'],
                    'unit_price_effective' => $item['initialPrice'] - ($item['discountTotal'] ?? 0),
                    'line_subtotal' => $item['initialPrice'] * $item['quantity'],
                    'line_discount_total' => $item['discountTotal'] ?? 0,
                    'line_total' => ($item['initialPrice'] * $item['quantity']) - ($item['discountTotal'] ?? 0),
                    'quantity' => $item['quantity'],
                    'created_at' => $item['createdAt']['date'] ?? null,
                ];
            }
        }

        // Добавляем метод доставки
        if (!empty($data['delivery']['code'])) {
            $code = $data['delivery']['code'];
            $result['shipping_method'] = $this->deliveryMethodMap[$code] ?? $code;
        } else {
            $result['shipping_method'] = 'pickup'; // Default fallback
        }

        // Добавляем адрес доставки, если он есть
        if (!empty($data['delivery']['address'])) {
            $result['address'] = [
                'address' => $data['delivery']['address']['text'] ?? '',
            ];

            if (!empty($data['delivery']['address']['building'])) {
                $result['address']['entrance'] = $data['delivery']['address']['building'];
            }

            if (!empty($data['delivery']['address']['flat'])) {
                $result['address']['flat'] = $data['delivery']['address']['flat'];
            }

            if (!empty($data['delivery']['address']['floor'])) {
                $result['address']['floor'] = $data['delivery']['address']['floor'];
            }

            if (!empty($data['delivery']['address']['intercom'])) {
                $result['address']['intercom'] = $data['delivery']['address']['intercom'];
            }

            if (!empty($data['delivery']['address']['block'])) {
                $result['address']['entrance'] = $data['delivery']['address']['block'];
            }

            if (!empty($data['delivery']['address']['coordinates'])) {
                $result['address']['latitude'] = $data['delivery']['address']['coordinates']['latitude'];
                $result['address']['longitude'] = $data['delivery']['address']['coordinates']['longitude'];
            }
        }

        // Добавляем временной слот доставки, если он есть
        if (!empty($data['delivery']['date'])) {
            $dateVal = $data['delivery']['date'];
            // Handle both string "2025-11-26" and array/object with date field
            $dateStr = is_array($dateVal) ? ($dateVal['date'] ?? null) : $dateVal;

            if ($dateStr) {
                $date = date('Y-m-d', strtotime($dateStr));
                $timeFrom = '00:00';
                $timeTo = '23:59';

                if (!empty($data['delivery']['time'])) {
                    $timeVal = $data['delivery']['time'];

                    // Extract 'from'
                    if (isset($timeVal['from'])) {
                        $fromVal = $timeVal['from'];
                        $timeFromStr = is_array($fromVal) ? ($fromVal['date'] ?? '00:00') : $fromVal;
                        $timeFrom = date('H:i', strtotime($timeFromStr));
                    }

                    // Extract 'to'
                    if (isset($timeVal['to'])) {
                        $toVal = $timeVal['to'];
                        $timeToStr = is_array($toVal) ? ($toVal['date'] ?? '23:59') : $toVal;
                        $timeTo = date('H:i', strtotime($timeToStr));
                    }
                }

                $result['slot_start_date'] = date('Y-m-d H:i:s', strtotime("$date $timeFrom"));
                $result['slot_end_date'] = date('Y-m-d H:i:s', strtotime("$date $timeTo"));
            }
        }

        // Добавляем трекинг-номер, если он есть
        if (!empty($data['delivery']['trackNumber'])) {
            $result['tracking_number'] = $data['delivery']['trackNumber'];
        }

        // Добавляем даты создания/обновления, если они есть
        if (!empty($data['createdAt']['date'])) {
            $result['created_at'] = $data['createdAt']['date'];
        }

        if (!empty($data['updatedAt']['date'])) {
            $result['updated_at'] = $data['updatedAt']['date'];
        }

        \Log::info('OrderConverter::fromRetailCRM', ['result' => $result]);

        return $result;
    }

    /**
     * Получает externalId продукта по ID оффера через API RetailCRM.
     * Нужен потому что в заказах RetailCRM возвращает offer.id без externalId.
     *
     * @param string $offerId ID оффера в RetailCRM
     * @return string|null externalId продукта или null
     */
    protected function getProductExternalIdByOfferId(string $offerId): ?string
    {
        if (!$this->retailCrmService) {
            return null;
        }

        try {
            // Кэшируем результаты в рамках одного запроса
            static $cache = [];
            if (isset($cache[$offerId])) {
                return $cache[$offerId];
            }

            // Пробуем найти по offerIds (если API поддерживает)
            $response = $this->retailCrmService->getProducts(['offerIds' => [(int)$offerId]]);

            if ($response && !empty($response->products)) {
                $product = $response->products[0];
                $externalId = $product->externalId ?? null;
                if ($externalId) {
                    $cache[$offerId] = $externalId;
                    Log::debug("Найден product.externalId по offerIds", [
                        'offer_id' => $offerId,
                        'product_id' => $product->id ?? null,
                        'external_id' => $externalId,
                    ]);
                    return $externalId;
                }
            }

            // Fallback: запрос по ids (может вернуть product с таким id)
            $response = $this->retailCrmService->getProducts(['ids' => [(int)$offerId]]);

            if ($response && !empty($response->products)) {
                $product = $response->products[0];

                // Проверяем есть ли offer с нужным id
                if (!empty($product->offers)) {
                    foreach ($product->offers as $offer) {
                        if ((string)($offer->id ?? '') === $offerId) {
                            $externalId = $product->externalId ?? null;
                            $cache[$offerId] = $externalId;
                            return $externalId;
                        }
                    }
                }

                // Если это сам product — используем его externalId
                $externalId = $product->externalId ?? null;
                $cache[$offerId] = $externalId;
                return $externalId;
            }

            $cache[$offerId] = null;
            return null;

        } catch (\Exception $e) {
            Log::warning("Ошибка при получении product по offer.id: " . $e->getMessage(), [
                'offer_id' => $offerId,
            ]);
            return null;
        }
    }

    /**
     * Поиск метаданных по external_id (не entity_id).
     *
     * @param string $entityType Тип сущности
     * @param string $externalId Внешний ID
     * @param string|null $shopCode Код магазина
     * @return \App\Models\System\EventSyncMetadata|null
     */
    protected function findMetadataByExternalId(string $entityType, string $externalId, ?string $shopCode = null)
    {
        $query = \App\Models\System\EventSyncMetadata::where('entity_type', $entityType)
            ->where('external_id', $externalId);

        if ($shopCode) {
            $query->where(function ($q) use ($shopCode) {
                $q->where('shop_code', $shopCode)
                  ->orWhereNull('shop_code');
            });
        }

        return $query->first();
    }
}
