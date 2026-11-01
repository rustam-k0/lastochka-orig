<?php

declare(strict_types=1);

namespace App\RabbitMQ\Conditions;

use App\Models\IntegrationConfig;
use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Publish condition для Category events.
 *
 * Запрещает публикацию, если для магазина (по shop_id категории) в таблице
 * integration_configs НЕТ записи с integration_type='1c' и
 * sync_categories_enabled=true.
 *
 * Значение sync_categories_enabled прилетает в 1c_parser через RabbitMQ
 * от admin_back — чекбокс «Синхронизировать категории из 1С» в форме
 * редактирования 1С-интеграции в курьерской админке.
 */
class CategoriesSyncPublishCondition implements PublishCondition
{
    /**
     * Поля категории, изменение которых должно уехать подписчикам.
     * synced_at / updated_at сюда не входят — технические, не несут изменений.
     *
     * @var string[]
     */
    public const SIGNIFICANT_FIELDS = [
        'name',
        'parent_id',
        'is_active',
        'sort',
    ];

    public function __invoke(Model $category): bool
    {
        // created (wasRecentlyCreated) и deleted (exists=false после hard delete)
        // публикуем как раньше. updated — только при изменении значимых полей:
        // иначе любой путь записи, который трогает лишь synced_at/updated_at,
        // рассылал бы category_1c.updated на весь классификатор (130k/день).
        if (!$category->wasRecentlyCreated && $category->exists && !$category->wasChanged(self::SIGNIFICANT_FIELDS)) {
            return false;
        }

        $shopId = $category->shop_id ?? null;

        if ($shopId === null) {
            return false;
        }

        return (bool) IntegrationConfig::query()
            ->where('integration_type', '1c')
            ->where('shop_id', $shopId)
            ->value('sync_categories_enabled');
    }
}
