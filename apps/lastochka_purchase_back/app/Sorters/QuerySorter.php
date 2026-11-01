<?php

namespace App\Sorters;

use Illuminate\Database\Eloquent\Builder;

class QuerySorter
{
  protected Builder $builder;
  protected array $args;
  protected string $table_name;
  protected $selects = [];

  /**
   * QuerySorter constructor.
   * @param $builder
   * @param $args
   * @param $table_name
   */
  public function __construct($builder, $args, $table_name)
  {
    $this->builder = $builder;
    $this->args = $args;
    $this->table_name = $table_name;
  }

  public function apply()
  {
    $this->selects[] = $this->table_name . ".*";

    foreach ($this->args as $order) {
      if (!$this->validateKeys($order)) {
        continue;
      }

      $column = $order["orderColumn"];

      if (method_exists($this, $column)) {
        $this->$column($order["orderBy"]);
      } else {
        // Если обработки на текущее поле нет, то builder будет искать это поле в самой сущности
        $this->builder->orderBy($column, $order["orderBy"]);
      }
    }

    foreach ($this->selects as $select) {
      $this->builder->select($select);
    }

    return $this->builder;
  }

  /**
   * Проверяет наличие необходимых значений в массиве
   * @param $array
   * @return bool
   */
  private function validateKeys($array): bool
  {
    $keys = ["orderBy", "orderColumn"];

    foreach ($keys as $key) {
      if (!key_exists($key, $array)) {
        return false;
      }
    }
    return true;
  }
}
