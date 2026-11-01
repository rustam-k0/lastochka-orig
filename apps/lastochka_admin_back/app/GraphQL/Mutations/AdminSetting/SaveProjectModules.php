<?php

namespace App\GraphQL\Mutations\AdminSetting;

use App\Models\AdminSetting;
use App\Models\User;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SaveProjectModules
{
    /**
     * Поддерживаемые типы модулей в project_modules.
     *
     * - boolean: классический переключатель (label + enabled).
     *   Используется существующими модулями (bonuses, b2b, supplements).
     *   Если type не указан — считается boolean (backward compat).
     * - select: выбор из нескольких вариантов (label + value + options[]).
     *   Используется новыми модулями вроде marketplace_mode, где нужен
     *   не on/off, а выбор режима работы (standard | marketplace и т.п.).
     */
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';

    public const SUPPORTED_TYPES = [
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
    ];

    public function __invoke($_, array $args): AdminSetting
    {
        $config = $args['config'];

        $decoded = json_decode($config, true);
        if ($decoded === null) {
            throw new \InvalidArgumentException('Некорректный JSON конфигурации');
        }

        $this->validate($decoded);

        $this->assertPriceTiersToggleAllowed($decoded);

        $saved = AdminSetting::setValue('project_modules', $config, 'json');

        // Сбросить кэш флага aggregator-режима (App\Aggregator\Support\AggregatorMode::CACHE_KEY).
        // Forget напрямую, без зависимости от класса — fork-safe: ключ может не существовать.
        Cache::forget('aggregator.enabled');

        // Сбросить кэш флага сетевого каталога (App\NetworkCatalog\Support\NetworkCatalogMode).
        app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->flushCache();

        // Сбросить кэш флага «1С ведёт карточку товара» (MID-305).
        Cache::forget(\App\Services\ModuleConfigService::CACHE_KEY_ONEC_CARD_MASTER);

        // Сбросить кэш флага «1С ведёт магазины и сотрудников» (MID-306).
        Cache::forget(\App\Services\ModuleConfigService::CACHE_KEY_ONEC_SHOPS_MASTER);

        // Сбросить кэш флага «Цены от количества» (MID-308).
        Cache::forget(ModuleConfigService::CACHE_KEY_PRICE_TIERS);

        // Сбросить кэш флага «Диапазоны стоимости доставки» (MID-280).
        Cache::forget(ModuleConfigService::CACHE_KEY_DELIVERY_PRICE_RANGES);

        return $saved;
    }

    /**
     * «Цены от количества» (MID-308): менять price_tiers.enabled может только
     * суперадмин, независимо от role_access_matrix_enabled. Без матрицы ролей
     * AdminPolicy::manageSettings пускает любого глобального админа, а флаг
     * меняет расчёт цен во всех сервисах.
     *
     * Сравнивается новое значение с сохранённым, отсутствующий ключ = false:
     * пересохранение блоба без смены этого ключа (правка любого другого модуля)
     * доступно всем, у кого есть manageSettings, как и раньше. Отказ атомарный:
     * блоб не сохраняется, ошибка приходит в формате валидации по ключу price_tiers.
     *
     * @param array<string, array<string, mixed>> $decoded
     *
     * @throws ValidationException
     */
    private function assertPriceTiersToggleAllowed(array $decoded): void
    {
        $key = ModuleConfigService::PRICE_TIERS_KEY;

        $storedRaw = AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY);
        $stored = $storedRaw ? json_decode($storedRaw, true) : null;

        $wasEnabled = is_array($stored)
            && is_array($stored[$key] ?? null)
            && (bool) ($stored[$key]['enabled'] ?? false);

        $nowEnabled = is_array($decoded[$key] ?? null)
            && (bool) ($decoded[$key]['enabled'] ?? false);

        if ($wasEnabled === $nowEnabled) {
            return;
        }

        $user = Auth::user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return;
        }

        throw ValidationException::withMessages([
            $key => ['Модуль «Цены от количества» включает и выключает только суперадминистратор.'],
        ]);
    }

    /**
     * Проверить корректность распарсенной конфигурации модулей.
     *
     * Публичный метод, чтобы можно было покрыть валидацию unit-тестами
     * без поднятия Laravel-контейнера и БД.
     *
     * @param array<string, array<string, mixed>> $decoded
     *
     * @throws \InvalidArgumentException
     */
    public function validate(array $decoded): void
    {
        foreach ($decoded as $key => $module) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Ключ модуля должен быть строкой');
            }
            if (!is_array($module)) {
                throw new \InvalidArgumentException("Модуль {$key} должен быть объектом");
            }

            $type = $module['type'] ?? self::TYPE_BOOLEAN;

            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Модуль {$key}: неподдерживаемый тип '{$type}'. Поддерживаются: "
                    . implode(', ', self::SUPPORTED_TYPES)
                );
            }

            if ($type === self::TYPE_BOOLEAN) {
                $this->validateBooleanModule($key, $module);
                continue;
            }

            if ($type === self::TYPE_SELECT) {
                $this->validateSelectModule($key, $module);
                continue;
            }
        }
    }

    /**
     * @param array<string, mixed> $module
     */
    private function validateBooleanModule(string $key, array $module): void
    {
        if (!array_key_exists('label', $module) || !array_key_exists('enabled', $module)) {
            throw new \InvalidArgumentException(
                "Модуль {$key}: boolean-модуль должен содержать label и enabled"
            );
        }
    }

    /**
     * @param array<string, mixed> $module
     */
    private function validateSelectModule(string $key, array $module): void
    {
        if (!array_key_exists('label', $module)) {
            throw new \InvalidArgumentException("Модуль {$key}: select-модуль должен содержать label");
        }
        if (!array_key_exists('value', $module)) {
            throw new \InvalidArgumentException("Модуль {$key}: select-модуль должен содержать value");
        }
        if (!array_key_exists('options', $module) || !is_array($module['options'])) {
            throw new \InvalidArgumentException(
                "Модуль {$key}: select-модуль должен содержать массив options"
            );
        }
        if ($module['options'] === []) {
            throw new \InvalidArgumentException(
                "Модуль {$key}: options не должны быть пустым массивом"
            );
        }

        $allowedValues = [];
        foreach ($module['options'] as $index => $option) {
            if (!is_array($option)) {
                throw new \InvalidArgumentException(
                    "Модуль {$key}: каждый элемент options должен быть объектом"
                );
            }
            if (!array_key_exists('value', $option) || !array_key_exists('label', $option)) {
                throw new \InvalidArgumentException(
                    "Модуль {$key}: опция #{$index} должна содержать value и label"
                );
            }
            $allowedValues[] = $option['value'];
        }

        if (!in_array($module['value'], $allowedValues, true)) {
            throw new \InvalidArgumentException(
                "Модуль {$key}: value '{$module['value']}' не входит в список options"
            );
        }
    }
}
