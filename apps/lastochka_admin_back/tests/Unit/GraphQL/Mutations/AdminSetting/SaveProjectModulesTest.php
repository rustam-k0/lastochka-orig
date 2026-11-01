<?php

namespace Tests\Unit\GraphQL\Mutations\AdminSetting;

use App\GraphQL\Mutations\AdminSetting\SaveProjectModules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты валидации SaveProjectModules::validate().
 *
 * Не поднимают Laravel-контейнер и не ходят в БД — проверяют только
 * логику валидации. Сохранение в БД делает AdminSetting::setValue(),
 * оно не меняется и покрыто старыми интеграционными тестами.
 */
class SaveProjectModulesTest extends TestCase
{
    private SaveProjectModules $mutation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutation = new SaveProjectModules();
    }

    /** @test */
    public function legacy_boolean_module_saves_successfully(): void
    {
        $this->mutation->validate([
            'bonuses' => [
                'label' => 'Бонусы',
                'enabled' => true,
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function select_module_with_valid_value_saves_successfully(): void
    {
        $this->mutation->validate([
            'marketplace_mode' => [
                'label' => 'Режим работы магазина',
                'type' => 'select',
                'value' => 'standard',
                'options' => [
                    ['value' => 'standard', 'label' => 'Стандарт'],
                    ['value' => 'marketplace', 'label' => 'Маркетплейс'],
                ],
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function select_module_without_options_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/options/');

        $this->mutation->validate([
            'marketplace_mode' => [
                'label' => 'Режим работы магазина',
                'type' => 'select',
                'value' => 'standard',
            ],
        ]);
    }

    /** @test */
    public function select_module_with_empty_options_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/options/');

        $this->mutation->validate([
            'marketplace_mode' => [
                'label' => 'Режим работы магазина',
                'type' => 'select',
                'value' => 'standard',
                'options' => [],
            ],
        ]);
    }

    /** @test */
    public function select_module_with_value_not_in_options_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/не входит в список/u');

        $this->mutation->validate([
            'marketplace_mode' => [
                'label' => 'Режим работы магазина',
                'type' => 'select',
                'value' => 'xyz',
                'options' => [
                    ['value' => 'standard', 'label' => 'Стандарт'],
                    ['value' => 'marketplace', 'label' => 'Маркетплейс'],
                ],
            ],
        ]);
    }

    /** @test */
    public function select_module_with_option_missing_label_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/опция/u');

        $this->mutation->validate([
            'marketplace_mode' => [
                'label' => 'Режим работы магазина',
                'type' => 'select',
                'value' => 'standard',
                'options' => [
                    ['value' => 'standard'],
                ],
            ],
        ]);
    }

    /** @test */
    public function mixed_boolean_and_select_modules_save(): void
    {
        $this->mutation->validate([
            'bonuses' => [
                'label' => 'Бонусы',
                'enabled' => true,
            ],
            'marketplace_mode' => [
                'label' => 'Режим работы магазина',
                'type' => 'select',
                'value' => 'marketplace',
                'options' => [
                    ['value' => 'standard', 'label' => 'Стандарт'],
                    ['value' => 'marketplace', 'label' => 'Маркетплейс'],
                ],
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function module_with_unknown_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/неподдерживаемый тип/u');

        $this->mutation->validate([
            'custom' => [
                'label' => 'Custom',
                'type' => 'xyz',
                'value' => 'foo',
            ],
        ]);
    }

    /**
     * Regression: «legacy-конфиг» из production — три boolean-модуля без type.
     * Должен валидироваться без изменений поведения (I-1 zero-change).
     *
     * @test
     */
    public function legacy_existing_modules_regression(): void
    {
        $this->mutation->validate([
            'bonuses' => [
                'label' => 'Бонусы',
                'enabled' => true,
            ],
            'b2b' => [
                'label' => 'B2B',
                'enabled' => false,
            ],
            'supplements' => [
                'label' => 'Добавки',
                'enabled' => true,
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function boolean_module_without_enabled_still_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/label и enabled/u');

        $this->mutation->validate([
            'bonuses' => [
                'label' => 'Бонусы',
            ],
        ]);
    }

    /** @test */
    public function module_value_not_array_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/должен быть объектом/u');

        $this->mutation->validate([
            'bonuses' => 'wat',
        ]);
    }
}
