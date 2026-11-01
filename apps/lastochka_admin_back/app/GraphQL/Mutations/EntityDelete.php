<?php

namespace App\GraphQL\Mutations;

use App\Models\Announcement;
use App\Models\AssemblyZone;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\MerchantReview;
use App\Models\ProductReview;

final class EntityDelete
{
  /**
   * Маппинг таблица → модель Eloquent.
   * Нужен чтобы delete() проходил через Eloquent и триггерил события
   * (SoftDeletes, RabbitMQ Observer и т.д.).
   */
  private const TABLE_MODELS = [
    Announcement::class,
    AssemblyZone::class,
    Attribute::class,
    Category::class,
    MerchantReview::class,
    ProductReview::class,
  ];

  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $table = $args['table'];
    $id = $args['id'];

    $modelClass = $this->resolveModelClass($table);

    if ($modelClass) {
      $model = $modelClass::findOrFail($id);
      $model->delete();
    } else {
      \DB::table($table)->where('id', $id)->update([
        'deleted_at' => now(),
      ]);
    }

    return [
      'success' => true,
      'message' => 'Запись успешно удалена',
    ];
  }

  private function resolveModelClass(string $table): ?string
  {
    foreach (self::TABLE_MODELS as $modelClass) {
      if ((new $modelClass)->getTable() === $table) {
        return $modelClass;
      }
    }

    return null;
  }

}
