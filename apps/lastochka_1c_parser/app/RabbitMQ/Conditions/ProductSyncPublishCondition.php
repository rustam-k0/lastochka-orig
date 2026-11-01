<?php

declare(strict_types=1);

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Publish condition для Product events.
 *
 * Задача — публиковать `product.updated` при обмене с 1С ТОЛЬКО когда реально
 * изменились каталожные поля товара (наименование, штрихкод, категория и т.п.),
 * а не на каждый импорт.
 *
 * Каждый обмен 1С вызывает `Product::updateOrCreate(...)` с `synced_at => now()`,
 * из-за чего модель всегда «грязная» и Eloquent стреляет `updated`-событием даже
 * когда содержательно товар не изменился. Без этого фильтра простое включение
 * `'updated'` в конфиге привело бы к лавине — `product.updated` на КАЖДЫЙ товар
 * КАЖДОЙ выгрузки (десятки тысяч событий за обмен), что положило бы consumer'ы
 * admin_back / storefront и забило бы outbox.
 *
 * Поведение:
 *  - created — публикуем всегда (новый товар обязан уехать целиком);
 *  - updated — только если изменилось хотя бы одно значимое каталожное поле.
 */
class ProductSyncPublishCondition implements PublishCondition
{
    /**
     * Каталожные поля, изменение которых должно уехать в курьерку/витрину.
     *
     * `synced_at` / `updated_at` сюда НЕ входят: они переписываются на каждом
     * обмене и не несут содержательного изменения для подписчиков.
     *
     * @var string[]
     */
    public const SIGNIFICANT_FIELDS = [
        'name',
        'sku',
        'code',
        'plu',
        'barcode',
        'barcodes',
        'description',
        'category_id',
        'attributes',
        'is_active',
        'deleted_at',
    ];

    public function __invoke(Model $product): bool
    {
        // Создание товара — публикуем всегда.
        if ($product->wasRecentlyCreated) {
            return true;
        }

        // Мягкое удаление. Пакет вешает слушателя и на eloquent.deleting, и на
        // eloquent.deleted, поэтому для SoftDeletes-модели одно удаление
        // породило бы два одинаковых события. В deleting колонка ещё пуста, в
        // deleted уже проставлена — проверка trashed() пропускает только второй
        // вызов и тем самым снимает дубль.
        if (method_exists($product, 'trashed') && $product->trashed()) {
            return true;
        }

        // Обновление — только при реальном изменении каталожных полей,
        // чтобы технический synced_at=now() на каждом обмене не плодил события.
        // deleted_at в списке значимых полей: снятие метки (восстановление
        // товара) обязано уехать вниз по цепочке.
        return $product->wasChanged(self::SIGNIFICANT_FIELDS);
    }
}
