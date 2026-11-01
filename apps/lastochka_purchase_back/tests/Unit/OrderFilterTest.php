<?php

namespace Tests\Unit;

use App\Filters\OrderFilter;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;

class OrderFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }
        Mockery::close();
        parent::tearDown();
    }

    private function makeBuilder(): \Mockery\MockInterface
    {
        return Mockery::mock(Builder::class);
    }

    public function test_types_applies_where_in_on_type_id(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldReceive('whereIn')
            ->once()
            ->with('type_id', [1, 2]);

        $filter = new OrderFilter($builder, ['types' => [1, 2]]);
        $filter->apply();
    }

    public function test_payment_types_applies_where_in_on_payment_type_id(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldReceive('whereIn')
            ->once()
            ->with('payment_type_id', [1]);

        $filter = new OrderFilter($builder, ['payment_types' => [1]]);
        $filter->apply();
    }

    public function test_amount_from_applies_gte_on_amount(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldReceive('where')
            ->once()
            ->with('amount', '>=', 100.0);

        $filter = new OrderFilter($builder, ['amount_from' => 100.0]);
        $filter->apply();
    }

    public function test_amount_to_applies_lte_on_amount(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldReceive('where')
            ->once()
            ->with('amount', '<=', 500.0);

        $filter = new OrderFilter($builder, ['amount_to' => 500.0]);
        $filter->apply();
    }

    public function test_client_search_applies_where_has_client(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldReceive('whereHas')
            ->once()
            ->with('client', Mockery::type('callable'));

        $filter = new OrderFilter($builder, ['client_search' => 'Иван']);
        $filter->apply();
    }

    public function test_client_search_closure_adds_like_on_all_columns(): void
    {
        $builder = $this->makeBuilder();
        $innerBuilder = $this->makeBuilder();

        $innerBuilder->shouldReceive('where')
            ->once()
            ->with('name', 'LIKE', '%Иван%')
            ->andReturnSelf();
        $innerBuilder->shouldReceive('orWhere')
            ->once()
            ->with('surname', 'LIKE', '%Иван%')
            ->andReturnSelf();
        $innerBuilder->shouldReceive('orWhere')
            ->once()
            ->with('phone', 'LIKE', '%Иван%')
            ->andReturnSelf();
        $innerBuilder->shouldReceive('orWhere')
            ->once()
            ->with('email', 'LIKE', '%Иван%')
            ->andReturnSelf();

        $builder->shouldReceive('whereHas')
            ->once()
            ->with('client', Mockery::on(function ($closure) use ($innerBuilder) {
                $closure($innerBuilder);
                return true;
            }));

        $filter = new OrderFilter($builder, ['client_search' => 'Иван']);
        $filter->apply();
    }

    public function test_null_values_are_skipped(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldNotReceive('where');
        $builder->shouldNotReceive('whereIn');
        $builder->shouldNotReceive('whereHas');

        $filter = new OrderFilter($builder, [
            'types' => null,
            'payment_types' => null,
            'amount_from' => null,
            'amount_to' => null,
            'client_search' => null,
            'collectors' => null,
        ]);
        $filter->apply();
        $this->assertTrue(true);
    }

    public function test_empty_array_values_are_skipped(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldNotReceive('whereIn');
        $builder->shouldNotReceive('where');

        $filter = new OrderFilter($builder, [
            'types' => [],
            'payment_types' => [],
            'collectors' => [],
        ]);
        $filter->apply();
        $this->assertTrue(true);
    }

    public function test_collectors_wraps_conditions_in_single_group(): void
    {
        $builder = $this->makeBuilder();
        $group = $this->makeBuilder();

        $group->shouldReceive('whereExists')
            ->once()
            ->with(Mockery::type('callable'));

        $builder->shouldReceive('where')
            ->once()
            ->with(Mockery::on(function ($closure) use ($group) {
                $closure($group);
                return true;
            }));

        $filter = new OrderFilter($builder, ['collectors' => [7]]);
        $filter->apply();
    }

    public function test_collectors_zero_code_uses_where_not_exists(): void
    {
        $builder = $this->makeBuilder();
        $group = $this->makeBuilder();

        $group->shouldNotReceive('whereExists');
        $group->shouldReceive('whereNotExists')
            ->once()
            ->with(Mockery::type('callable'));

        $builder->shouldReceive('where')
            ->once()
            ->with(Mockery::on(function ($closure) use ($group) {
                $closure($group);
                return true;
            }));

        $filter = new OrderFilter($builder, ['collectors' => [0]]);
        $filter->apply();
    }

    public function test_collectors_combines_ids_and_zero_code_via_or(): void
    {
        $builder = $this->makeBuilder();
        $group = $this->makeBuilder();

        $group->shouldReceive('whereExists')
            ->once()
            ->with(Mockery::type('callable'));
        $group->shouldReceive('orWhereNotExists')
            ->once()
            ->with(Mockery::type('callable'));

        $builder->shouldReceive('where')
            ->once()
            ->with(Mockery::on(function ($closure) use ($group) {
                $closure($group);
                return true;
            }));

        $filter = new OrderFilter($builder, ['collectors' => [0, 7]]);
        $filter->apply();
    }

    public function test_multiple_filters_combined(): void
    {
        $builder = $this->makeBuilder();
        $builder->shouldReceive('where')->once()->with('amount', '>=', 100.0);
        $builder->shouldReceive('where')->once()->with('amount', '<=', 5000.0);
        $builder->shouldReceive('whereIn')->once()->with('type_id', [2]);
        $builder->shouldReceive('whereIn')->once()->with('payment_type_id', [1, 2]);

        $filter = new OrderFilter($builder, [
            'types' => [2],
            'payment_types' => [1, 2],
            'amount_from' => 100.0,
            'amount_to' => 5000.0,
        ]);
        $filter->apply();
    }
}
