<?php

namespace App\Services\Converters;

class ProductConverter extends BaseConverter
{
    public function __construct(array $config = null)
    {
        $this->retailcrmSource = $config['source'] ?? config('retailcrm.source', 'retailcrm');
    }

    public function fromRetailCRM(array $data, $metadata = null): array
    {
        $updatedAt = $data['updatedAt'] ?? null;
        if (is_array($updatedAt) && isset($updatedAt['date'])) {
            $updatedAt = $updatedAt['date'];
        }

        // Получаем данные из первого торгового предложения
        $price = 0;
        $weight = 0;
        $quantity = 0;
        $barcode = null;
        $sku = $data['article'] ?? '';

        if (!empty($data['offers'])) {
            $offer = $data['offers'][0];

            // Пробуем найти базовую цену в массиве prices
            if (!empty($offer['prices'])) {
                foreach ($offer['prices'] as $priceItem) {
                    if (($priceItem['code'] ?? '') === 'base') {
                        $price = $priceItem['price'] ?? 0;
                        break;
                    }
                }
                // Если базовой нет, берем первую доступную
                if ($price == 0 && isset($offer['prices'][0]['price'])) {
                    $price = $offer['prices'][0]['price'];
                }
            }

            // Если в prices не нашли, пробуем старое поле price
            if ($price == 0) {
                $price = $offer['price'] ?? 0;
            }

            // Вес
            if (!empty($offer['weight'])) {
                $weight = $offer['weight'];
            }

            // Количество
            if (isset($offer['quantity'])) {
                $quantity = $offer['quantity'];
            }

            // Штрихкод
            if (!empty($offer['barcode'])) {
                $barcode = $offer['barcode'];
            }

        } elseif (isset($data['price'])) {
            // Fallback если передали оффер как продукт или старая структура
            $price = $data['price'];
            $weight = $data['weight'] ?? 0;
            $quantity = $data['quantity'] ?? 0;
        }

        // Если вес не найден в оффере, смотрим в продукте
        if ($weight == 0 && !empty($data['weight'])) {
            $weight = $data['weight'];
        }

        // Если кол-во не найдено в оффере, смотрим в продукте
        if ($quantity == 0 && isset($data['quantity'])) {
            $quantity = $data['quantity'];
        }

        // Determine source and ID from metadata if available
        // Без metadata используем externalId (id из Bagisto) если есть, иначе retailCrmId
        $id = (string) ($data['externalId'] ?? $data['id']);
        $source = $this->retailcrmSource;

        if ($metadata) {
            $source = $metadata->source;
            // Use external_id as the canonical ID if it exists in metadata
            // This assumes external_id in metadata maps to the UUID/ID in the source system
            $id = $metadata->external_id;
        }

        $result = [
            'id' => $id,
            'shop_code' => $this->extractShopCode($data),
            'source' => $source,
            'sku' => $sku,
            'title' => $data['name'] ?? '',
            'price' => (float) $price,
            'quantity' => (float) $quantity,
            'weight' => (int) $weight,
            'active' => isset($data['active']) ? (bool)$data['active'] : true,
            'unit_type' => 'piece',
            'updated_at' => $updatedAt,
        ];

        if ($barcode) {
             $result['barcodes'] = [$barcode];
        }

        // If we used metadata, we might want to preserve the link to RetailCRM ID in 'externalId' field?
        // Usually 'externalId' in the event means "ID in the source system".
        // If source is NOT retailcrm, then 'id' is the ID in the source system.
        // So 'externalId' should also be that ID?
        // Or do we put RetailCRM ID somewhere else?
        // Existing logic used RetailCRM ID as 'id'.

        // If source is 'retailcrm', id is RetailCRM ID.
        // If source is '1c', id is 1C ID.

        // The converter contract usually expects 'id' to be the unique identifier in the MESSAGE BUS context (UUID).

        if (!empty($data['imageUrl'])) {
            $result['image_url'] = $data['imageUrl'];
        }

        if (!empty($data['description'])) {
            $result['description'] = $data['description'];
        }

        if (!empty($data['groups'])) {
            $group = is_array($data['groups']) ? ($data['groups'][0] ?? null) : null;
             if ($group) {
                 $categoryRetailId = (string) ($group['id'] ?? '');
                 // Ищем metadata категории по entity_id чтобы получить правильный source и external_id
                 $categoryMeta = \App\Models\System\EventSyncMetadata::where('entity_type', 'category')
                     ->where('entity_id', $categoryRetailId)
                     ->first();
                 
                 if ($categoryMeta) {
                     // Категория найдена - берём source и external_id из metadata
                     $result['category_id'] = $categoryMeta->external_id;
                     $result['category_source'] = $categoryMeta->source;
                 } else {
                     // Категория не найдена в metadata - используем retailCrmId
                     $result['category_id'] = $categoryRetailId;
                     $result['category_source'] = $this->retailcrmSource;
                 }
             }
        }

        return $result;
    }

    public function toRetailCRM(array $data): array
    {
        $result = [
            'name' => $data['title'] ?? $data['name'] ?? '',
            'article' => $data['sku'] ?? $data['article'] ?? '',
            'active' => $data['active'] ?? true,
        ];

        // Добавляем externalId для связи
        if (!empty($data['id'])) {
            $result['externalId'] = (string)$data['id'];
        }

        // Формируем данные оффера (SKU, цена, остатки)
        $offer = [
            'article' => $result['article'],
        ];

        if (!empty($data['id'])) {
             $offer['externalId'] = (string)$data['id'];
        }

        // Цена
        if (isset($data['price'])) {
            $result['price'] = (float)$data['price']; // Оставляем для совместимости

            // Цена в оффере
            $offer['prices'] = [
                [
                    'code' => 'base',
                    'price' => (float)$data['price']
                ]
            ];
        }

        // Вес (RetailCRM ожидает вес в граммах)
        if (isset($data['weight'])) {
            $result['weight'] = (int)$data['weight'];
            $offer['weight'] = (int)$data['weight'];
        }

        // Количество
        if (isset($data['quantity'])) {
            $result['quantity'] = (float)$data['quantity'];
            $offer['quantity'] = (float)$data['quantity'];
        }

        // Штрихкоды
        if (!empty($data['barcodes']) && is_array($data['barcodes'])) {
            $result['barcodes'] = $data['barcodes'];
            $offer['barcodes'] = $data['barcodes'];
        }

        // Добавляем сформированный оффер
        $result['offers'] = [$offer];

        // Описание
        if (!empty($data['description'])) {
            $result['description'] = $data['description'];
        }

        // Изображение
        if (!empty($data['image_url'])) {
            $result['imageUrl'] = $data['image_url'];
        }

        // Категория (группа товаров) - будет добавлена через metadata lookup
        // Это делается в handler-е, не здесь

        return $result;
    }
}
