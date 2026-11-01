<?php


namespace App\Filters;


use Illuminate\Database\Eloquent\Builder;

class QueryFilter
{
  protected Builder $builder;
  protected array $args;

  public function __construct(Builder $builder,array $args)
  {
    $this->builder = $builder;
    $this->args = $args;
  }

  public function apply(): Builder
  {
    foreach ($this->args as $filter => $value) {
      if (!method_exists($this, $filter)) {
        continue;
      }

      if ($value === null) {
        continue;
      }

      if (!is_array($value)) {
        $this->$filter($value);
        continue;
      }

      if (count($value)) {
        $this->$filter($value);
      }
    }

    return $this->builder;
  }

  public function title(string $value): void
  {
    $this->builder->where('title', 'LIKE', "%$value%");
  }

  public function status(int $value): void
  {
    $this->builder->where('status_id',$value);
  }

  /**
   * Выбрать начиная от
   * @param string $value
   */
  public function updated_at_start_date(string $value): void
  {
    $this->builder->where('updated_at', '>=', $value);
  }

  /**
   * Выбрать до
   * @param string $value
   */
  public function updated_at_end_date(string $value): void
  {
    $this->builder->where('updated_at', '<=', $value);
  }


}
