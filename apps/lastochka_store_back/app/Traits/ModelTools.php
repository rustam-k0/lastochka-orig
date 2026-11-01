<?php

namespace App\Traits;

use App\Contracts\OnlyActive;
use App\Filters\QueryFilter;
use App\Models\Scopes\ActiveScope;
use App\Models\Status;
use App\Models\Type;
use App\Sorters\QuerySorter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Методы которые нужны во всех моделях
 * Trait ModelTools
 * @package App\Traits
 */
trait ModelTools
{
  /**
   * Получение записи статуса
   * @return HasOne|null
   */
  public function status(): ?HasOne
  {
    return $this->hasOne(Status::class, 'code', 'status_id')->where('table_name', self::table);
  }

  /**
   * Если у сущности есть тип то возвращается связка
   * @return HasOne|null
   */
  public function type(): ?HasOne
  {
    return $this->hasOne(Type::class, 'code', 'type_id')->where('table_name', self::table);
  }

  /**
   * Скоп только активных
   * @param $query
   * @return mixed
   */
  public function scopeOnlyActive($query): Builder
  {
    return $query->whereStatusId(Status::CODE_ACTIVE);
  }

  /**
   * Получение активного статуса
   * @return integer - активный статус
   */
  public static function active(): int
  {
    return Status::CODE_ACTIVE;
  }

  /**
   * Получение не опубликованного статуса
   * @return integer - активный статус
   */
  public static function notPublished(): int
  {
    return Status::CODE_NOT_PUBLISHED;
  }

  /**
   * Создание уникальной ссылки
   *
   * @param $string - строка которая должна быть уникальной
   * @param null $id - id текущей модели которая редактируется
   * @return string|null - может вернуть null!!
   */
  public static function getUniqueLink($string, $id = null): ?string
  {
    $link = null;
    if (isset($string)) {
      $link = Str::slug($string);
      // Если есть id значит надо проверить чтобы
      // результат where исключал текущую модель
      // Это обработка случая редактирования
      if (isset($id)) {
        // Если новая ссылка не равна уже существующей
        if (!self::where(["link" => $link])->first()) {
          $exists = self::where([
            ["id", "!=", $id],
            ["link", "regexp", $link . "(-\d+)?"],
          ])->get();
        }
      } else {
        $exists = self::where([["link", "regexp", $link . "(-\d+)?"]])->get();
      }
      // Если не уникально то в конец добавляется '-{число}'
      if (isset($exists) && $exists->count() > 0) {
        $link = $link . "-" . $exists->count();
      }
    }
    return $link;
  }

  /**
   * Скоп на применение фильтра
   * @param $query
   * @param $args
   * @return mixed
   */
  public function scopeFilter($query, $args): mixed
  {
    $filter_name = self::getShortClassName() . "Filter";

    $class = "App\\Filters\\" . $filter_name;

    if (!class_exists($class)) {
      return (new QueryFilter($query, $args))->apply();
    }

    return (new $class($query, $args))->apply();
  }

  /**
   * Скоп на применение сортировки
   */
  public function scopeSorter(Builder $query, ?array $args): Builder
  {
    if (!isset($args["ordering"])) {
      return $query;
    }

    $filter_name = self::getShortClassName() . "Sorter";

    $class = "App\\Sorters\\" . $filter_name;

    if (!class_exists($class)) {
      return (new QuerySorter($query, $args["ordering"], self::table))->apply();
    }

    return (new $class($query, $args["ordering"], self::table))->apply();
  }

  /**
   * Получение названия класса без неймспейса,
   * пример: App\Models\Course преобразуется в Course
   */
  public static function getShortClassName(): ?string
  {
    $path = explode("\\", __CLASS__);
    return array_pop($path);
  }

  public static function customizeQuery(Builder $query): Builder
  {
    return $query;
  }

  protected static function booted(): void
  {

    // Если модель реализует интерфейс OnlyActive, то добавляем глобальный скоп,
    // который будет фильтровать только активные записи
    if (in_array(OnlyActive::class, class_implements(self::class))) {
      static::addGlobalScope(new ActiveScope);
    }

  }




}
