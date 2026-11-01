<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Store;
use App\Models\StoreSchedule;
use App\Services\Pricing\DeliveryPriceLadder;
use App\Services\Settings\ModuleConfigService;
use App\Services\Store\AvailableStoreService;
use App\Services\Store\CityResolver;
use App\Support\OneCBool;
use App\Support\OneCInt;
use App\Support\OneCNumber;
use App\Support\OneCWeekSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StoreEventTransformer extends BaseEventTransformer
{
    private const DAY_MAP = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    /**
     * Атрибут stores => ключи события, которые его несут (MID-306). Для события 1С
     * при модуле onec_shops_master атрибут без присланного ключа не пишется.
     * is_open_24_7 решается по open_24_7 и schedule (schedule без open_24_7 снимает 24/7).
     */
    private const ONEC_ATTRIBUTE_KEYS = [
        'name' => ['title'],
        'is_active' => ['active'],
        'slot_duration' => ['slot_time'],
        'is_open_24_7' => ['open_24_7', 'schedule'],
        'is_b2b' => ['is_b2b'],
        'delivery_price' => ['delivery_price'],
        'min_order_amount' => ['min_order_price'],
        'free_delivery_from' => ['free_delivery_from'],
        'is_bonus_enabled' => ['is_bonus_enabled'],
        'bonus_earn_percent' => ['bonus_earn_percent'],
        'bonus_spend_percent' => ['bonus_spend_percent'],
        'ignore_stock' => ['ignore_stock'],
        'description' => ['description'],
        'is_pickup_enabled' => ['is_pickup_enabled'],
        'min_pickup_order_amount' => ['min_pickup_order_amount'],
        'pickup_payment_methods' => ['pickup_payment_methods'],
        'pickup_auto_cancel_enabled' => ['pickup_auto_cancel_enabled'],
        'pickup_wait_perishable_hours' => ['pickup_wait_perishable_hours'],
        'pickup_wait_regular_hours' => ['pickup_wait_regular_hours'],
        'pickup_grace_period_hours' => ['pickup_grace_period_hours'],
        'heavy_order_threshold' => ['heavy_order_threshold', 'heavy_order_threshold_kg'],
        'assembly_price' => ['assembly_price'],
    ];

    /**
     * Булевы NOT NULL колонки stores => ключ события 1С (MID-306): пустое значение и мусор не меняют поле.
     */
    private const ONEC_BOOL_ATTRIBUTES = [
        'is_active' => 'active',
        'is_b2b' => 'is_b2b',
        'ignore_stock' => 'ignore_stock',
        'is_bonus_enabled' => 'is_bonus_enabled',
        'is_pickup_enabled' => 'is_pickup_enabled',
        'pickup_auto_cancel_enabled' => 'pickup_auto_cancel_enabled',
    ];

    /**
     * Целые колонки stores => [ключ события 1С, min, max, пустое значение очищает поле] (MID-306).
     * Вне диапазона и мусор не меняют поле; slot_time как в админке: пустое не меняет.
     */
    private const ONEC_INT_ATTRIBUTES = [
        'slot_duration' => ['slot_time', 1, 2147483647, false],
        'bonus_earn_percent' => ['bonus_earn_percent', 0, 100, false],
        'bonus_spend_percent' => ['bonus_spend_percent', 0, 100, false],
        'delivery_price' => ['delivery_price', 0, 2147483647, true],
        'min_order_amount' => ['min_order_price', 0, 2147483647, true],
        'free_delivery_from' => ['free_delivery_from', 0, 2147483647, true],
        'min_pickup_order_amount' => ['min_pickup_order_amount', 0, 2147483647, true],
        'assembly_price' => ['assembly_price', 0, 2147483647, true],
        'heavy_order_threshold' => ['heavy_order_threshold', 0, 4294967295, true],
        'pickup_wait_perishable_hours' => ['pickup_wait_perishable_hours', 0, 255, false],
        'pickup_wait_regular_hours' => ['pickup_wait_regular_hours', 0, 255, false],
        'pickup_grace_period_hours' => ['pickup_grace_period_hours', 0, 255, false],
    ];

    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'shop_code', 'source'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $store, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Store.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'code' => $data['shop_code'],
            'source' => $data['source'],
            'name' => $data['title'] ?? null,
            'is_active' => $data['active'] ?? true,
            'type' => ($data['type'] ?? null) === 'restaurant' ? 'restaurant' : 'shop',
            'slot_duration' => $data['slot_time'] ?? null,
            'is_open_24_7' => $data['open_24_7'] ?? false,
            'is_b2b' => $data['is_b2b'] ?? false,
            'delivery_price' => isset($data['delivery_price']) ? (int) $data['delivery_price'] : null,
            'min_order_amount' => isset($data['min_order_price']) ? (int) $data['min_order_price'] : null,
            'free_delivery_from' => isset($data['free_delivery_from']) ? (int) $data['free_delivery_from'] : null,
            'is_bonus_enabled' => $data['is_bonus_enabled'] ?? false,
            'bonus_earn_percent' => (int) ($data['bonus_earn_percent'] ?? 0),
            'bonus_spend_percent' => (int) ($data['bonus_spend_percent'] ?? 0),
            'ignore_stock' => (bool) ($data['ignore_stock'] ?? false),
            'description' => $data['description'] ?? null,
            'is_pickup_enabled' => $data['is_pickup_enabled'] ?? false,
            'min_pickup_order_amount' => isset($data['min_pickup_order_amount']) ? (int) $data['min_pickup_order_amount'] : null,
            'pickup_payment_methods' => is_array($data['pickup_payment_methods'] ?? null)
                ? $data['pickup_payment_methods']
                : (is_string($data['pickup_payment_methods'] ?? null)
                    ? (json_decode($data['pickup_payment_methods'], true) ?: ['card'])
                    : ['card']),
            'pickup_auto_cancel_enabled' => $data['pickup_auto_cancel_enabled'] ?? true,
            'pickup_wait_perishable_hours' => (int) ($data['pickup_wait_perishable_hours'] ?? 1),
            'pickup_wait_regular_hours' => (int) ($data['pickup_wait_regular_hours'] ?? 3),
            'pickup_grace_period_hours' => (int) ($data['pickup_grace_period_hours'] ?? 1),
            'heavy_order_threshold' => isset($data['heavy_order_threshold']) ? (int) $data['heavy_order_threshold'] : null,
            'assembly_price' => isset($data['assembly_price']) ? (int) $data['assembly_price'] : null,
        ];

        // Координаты применяем только если они валидные (ненулевые). admin_back
        // публикует магазин с latitude=0/longitude=0, когда координаты в админке
        // курьерки не заданы (поле `source` при этом = origin магазина, напр.
        // `retailbridge`, и НЕ означает «координат нет»). Такие нули НЕ должны
        // затирать реальные координаты магазина, иначе Яндекс-доставка падает с
        // estimating.claim.no_zone_id → 500 при оформлении заказа (ALADDIN-11465).
        // Фильтр по нулю (а не по источнику): валидные координаты принимаются от
        // любого источника, чтобы обновление координат в курьерке синкалось в витрину.
        if (isset($data['latitude']) && (float) $data['latitude'] !== 0.0) {
            $attributes['latitude'] = $data['latitude'];
        }
        if (isset($data['longitude']) && (float) $data['longitude'] !== 0.0) {
            $attributes['longitude'] = $data['longitude'];
        }

        // Адрес магазина (MENDEV-16): conditional set — раньше событие без
        // ключа address затирало адрес витрины в null (админка вообще не слала
        // это поле, из-за чего адрес с обмена «не сохранялся» на витрине).
        if (array_key_exists('address', $data)) {
            $attributes['address'] = $data['address'];
        }

        // Город: city_name либо населённый пункт из адреса (1С шлёт только адрес),
        // неизвестный город создаётся. Ключ не трогаем, если определить город
        // нечем — раньше city_id безусловно сбрасывался в null.
        $cityId = $this->resolveCityId($data);
        if ($cityId !== null) {
            $attributes['city_id'] = $cityId;
        }

        // Магазин по умолчанию в городе: conditional set — событие без ключа
        // (старая версия курьерки) не сбрасывает уже проставленный флаг.
        if (array_key_exists('is_default', $data)) {
            $attributes['is_default'] = (bool) $data['is_default'];
        }

        // Динамические чекбоксы: conditional set — события без ключа
        // не затирают локальные значения.
        if (array_key_exists('custom_checkboxes', $data)) {
            $attributes['custom_checkboxes'] = is_array($data['custom_checkboxes'])
                ? array_map(fn ($v) => (bool) $v, $data['custom_checkboxes'])
                : [];
        }

        // Ступени стоимости доставки основной зоны (MID-280): conditional set, ключ ведёт
        // только админка. От 1С не читается (контракт событий магазина: настройки доставки
        // живут в админке), нет ключа: колонку не трогаем, [] очищает.
        if (array_key_exists('delivery_price_ranges', $data) && ($data['event_source'] ?? $data['source'] ?? null) !== '1c') {
            $attributes['delivery_price_ranges'] = $this->deliveryPriceRanges($data);
        }

        // MID-306: магазины 1С при модуле «1С ведёт магазины и сотрудников» всегда «Магазин»,
        // значения проверяются по правилу полей, порог тяжёлого заказа 1С присылает в килограммах.
        if ($this->isOneCShopsMasterEvent($data)) {
            $attributes['type'] = 'shop';
            $attributes = $this->applyOneCValues($data, $attributes);

            if (array_key_exists('heavy_order_threshold_kg', $data)) {
                $grams = $this->heavyThresholdGrams($data['heavy_order_threshold_kg']);

                if ($grams === false) {
                    unset($attributes['heavy_order_threshold']);
                    Log::warning('1C shop event: heavy_order_threshold_kg is invalid, threshold not changed', [
                        'shop_code' => $data['shop_code'] ?? null,
                    ]);
                } else {
                    $attributes['heavy_order_threshold'] = $grams;
                }
            }
        }

        return $attributes;
    }

    /**
     * После создания магазина — синхронизировать расписание и скачать изображение.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $store, array $eventData): void
    {
        if ($this->isOneCShopsMasterEvent($eventData)) {
            $this->applyOneCSchedule($store, $eventData);
        } else {
            $this->syncSchedule($store, $eventData['schedule'] ?? []);
        }

        $this->processImage($store, $eventData);
        $this->invalidateAvailableStoresCache();
    }

    /**
     * После обновления магазина — синхронизировать расписание и обновить изображение.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $store, array $eventData): void
    {
        if ($this->isOneCShopsMasterEvent($eventData)) {
            $this->applyOneCSchedule($store, $eventData);
        } elseif (isset($eventData['schedule'])) {
            $this->syncSchedule($store, $eventData['schedule']);
        }

        if (isset($eventData['image_url'])) {
            $this->processImage($store, $eventData);
        }

        $this->invalidateAvailableStoresCache();
    }

    /**
     * MID-306: для события 1С при модуле onec_shops_master «магазин по умолчанию» без города не ставится.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (! $this->isOneCShopsMasterEvent($eventData)) {
            return $transformedData;
        }

        return $this->withoutDefaultWithoutCity($eventData, $transformedData, null);
    }

    /**
     * MID-306: для события 1С при модуле onec_shops_master поле без присланного ключа
     * не пишется, пустое название не меняет магазин, тип не меняется, «магазин по умолчанию»
     * без города не ставится. Пустые и некорректные значения отброшены в fromEvent().
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeUpdate(Model $model, array $eventData, array $transformedData): ?array
    {
        if (! $this->isOneCShopsMasterEvent($eventData)) {
            return $transformedData;
        }

        unset($transformedData['type']);

        foreach (self::ONEC_ATTRIBUTE_KEYS as $attribute => $keys) {
            if (! array_key_exists($attribute, $transformedData)) {
                continue;
            }

            $presentKeys = array_filter($keys, fn (string $key) => array_key_exists($key, $eventData));

            if ($presentKeys === []) {
                unset($transformedData[$attribute]);
            }
        }

        if (array_key_exists('title', $eventData) && $this->oneCText($eventData['title']) === null) {
            unset($transformedData['name']);
            $this->warnOneC('title is empty or invalid, store name not changed', $eventData);
        }

        return $this->withoutDefaultWithoutCity($eventData, $transformedData, $model);
    }

    /**
     * Значения события 1С по правилу полей (MID-306): присланное пустое значение очищает nullable
     * поле и не меняет NOT NULL; некорректное значение не меняет поле; всё — с warning.
     * Атрибут без значения удаляется: на создании остаётся дефолт колонки.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyOneCValues(array $data, array $attributes): array
    {
        // Пустое название на создании — код магазина (как в админке), на обновлении не меняет (beforeUpdate).
        if (array_key_exists('title', $data) && $this->oneCText($data['title']) === null) {
            $attributes['name'] = (string) $data['shop_code'];
        }

        foreach (self::ONEC_BOOL_ATTRIBUTES as $attribute => $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            [$valid, $value] = OneCBool::parse($data[$key]);

            if ($value === null) {
                unset($attributes[$attribute]);
                $this->warnOneC($valid ? "{$key} is empty, not changed" : "{$key} is not a boolean, not changed", $data);
            } else {
                $attributes[$attribute] = $value;
            }
        }

        foreach (self::ONEC_INT_ATTRIBUTES as $attribute => [$key, $min, $max, $nullable]) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            [$valid, $value] = OneCInt::parse($data[$key], $min, $max);

            if (! $valid || ($value === null && ! $nullable)) {
                unset($attributes[$attribute]);
                $this->warnOneC("{$key} is empty or not an integer {$min}..{$max}, not changed", $data);
            } else {
                $attributes[$attribute] = $value;
            }
        }

        if (array_key_exists('pickup_payment_methods', $data)) {
            $methods = $this->oneCPaymentMethods($data['pickup_payment_methods']);

            if ($methods === null) {
                unset($attributes['pickup_payment_methods']);
                $this->warnOneC('pickup_payment_methods is not a list of strings, not changed', $data);
            } else {
                $attributes['pickup_payment_methods'] = $methods;
            }
        }

        // Координаты 1С: пустое значение очищает (в отличие от событий курьерки, где нули не затирают).
        foreach (['latitude' => 90, 'longitude' => 180] as $key => $limit) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $coordinate = OneCNumber::parse($data[$key]);

            if ($coordinate === false || ($coordinate !== null && abs($coordinate) > $limit)) {
                unset($attributes[$key]);
                $this->warnOneC("{$key} is invalid, not changed", $data);
            } else {
                $attributes[$key] = $coordinate;
            }
        }

        unset($attributes['is_open_24_7']);
        $allDay = $this->oneCAllDay($data, true);
        $hasSchedule = array_key_exists('schedule', $data);

        if ($hasSchedule && OneCWeekSchedule::parse($data['schedule']) === false) {
            $this->warnOneC('schedule is invalid, is_open_24_7 and schedule not changed', $data);
        } elseif ($allDay !== null) {
            $attributes['is_open_24_7'] = $allDay;
        } elseif ($hasSchedule) {
            $attributes['is_open_24_7'] = false;
        }

        return $attributes;
    }

    /**
     * Режим работы из события 1С (MID-306), то же правило, что в админке:
     * - schedule невалиден → ни график, ни is_open_24_7 не меняются (fromEvent);
     * - open_24_7 = true → круглосуточно все дни: график дней не хранится, дни schedule не учитываются;
     * - schedule пришёл (open_24_7 не true) → график заменяется целиком, null или {} очищают его;
     * - open_24_7 = false без schedule или нет обоих ключей → график не меняется.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function applyOneCSchedule(Store $store, array $eventData): void
    {
        $hasSchedule = array_key_exists('schedule', $eventData);
        $allDay = $this->oneCAllDay($eventData, false);

        if (! $hasSchedule && $allDay !== true) {
            return;
        }

        $days = $hasSchedule ? OneCWeekSchedule::parse($eventData['schedule']) : [];

        if ($days === false) {
            return;
        }

        if ($allDay === true) {
            if ($days !== [] && count(array_filter($days, fn ($interval) => $interval !== null)) < count(OneCWeekSchedule::DAYS)) {
                $this->warnOneC('open_24_7 applies to all days, schedule days ignored', $eventData);
            }

            $store->schedules()->delete();

            return;
        }

        DB::transaction(function () use ($store, $days) {
            $store->schedules()->delete();

            foreach ($days as $day => $interval) {
                StoreSchedule::create([
                    'store_id' => $store->id,
                    'day_of_week' => $day,
                    'opens_at' => $interval['start'] ?? null,
                    'closes_at' => $interval['end'] ?? null,
                ]);
            }
        });
    }

    /**
     * open_24_7 из события 1С: true/false; null — ключа нет, пустое значение или мусор.
     *
     * @param  array<string, mixed>  $data
     */
    private function oneCAllDay(array $data, bool $warn): ?bool
    {
        if (! array_key_exists('open_24_7', $data)) {
            return null;
        }

        [$valid, $allDay] = OneCBool::parse($data['open_24_7']);

        if ($allDay === null && $warn) {
            $this->warnOneC($valid ? 'open_24_7 is empty, ignored' : 'open_24_7 is not a boolean, ignored', $data);
        }

        return $allDay;
    }

    /**
     * «Магазин по умолчанию» без города (ни в событии, ни у магазина) не ставится (как в админке).
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withoutDefaultWithoutCity(array $eventData, array $attributes, ?Model $store): array
    {
        if (($attributes['is_default'] ?? false) === true && empty($attributes['city_id']) && empty($store?->city_id)) {
            unset($attributes['is_default']);
            $this->warnOneC('is_default ignored: store city is unknown', $eventData);
        }

        return $attributes;
    }

    /**
     * Способы оплаты самовывоза: непустой список непустых строк или JSON-строка такого списка; иначе null.
     *
     * @return list<string>|null
     */
    private function oneCPaymentMethods(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw) || $raw === [] || ! array_is_list($raw)) {
            return null;
        }

        foreach ($raw as $method) {
            if (! is_string($method) || trim($method) === '') {
                return null;
            }
        }

        return $raw;
    }

    /**
     * Строка из события 1С: trim, пустая → null; не строка и не число → null.
     */
    private function oneCText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function warnOneC(string $message, array $eventData): void
    {
        Log::warning('1C shop event: ' . $message, [
            'event_id' => $eventData['event_id'] ?? null,
            'shop_code' => $eventData['shop_code'] ?? null,
        ]);
    }

    /**
     * Событие 1С (source и event_source = 1c) при модуле «1С ведёт магазины и сотрудников».
     *
     * @param  array<string, mixed>  $data
     */
    private function isOneCShopsMasterEvent(array $data): bool
    {
        $source = $data['source'] ?? null;

        return $source === '1c'
            && ($data['event_source'] ?? $source) === '1c'
            && app(ModuleConfigService::class)->isOneCShopsMasterEnabled();
    }

    /**
     * Ступени доставки из события (MID-280): нормализованы, битые отброшены с warning.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{from_amount: int, price: int}>
     */
    private function deliveryPriceRanges(array $data): array
    {
        $ranges = DeliveryPriceLadder::normalize($data['delivery_price_ranges']);

        if ($ranges === null) {
            Log::warning('Shop event: delivery_price_ranges is invalid, ranges dropped', [
                'shop_code' => $data['shop_code'] ?? null,
            ]);

            return [];
        }

        return $ranges;
    }

    /**
     * Порог тяжёлого заказа: килограммы из 1С → граммы; null — очистить; false — невалидно.
     */
    private function heavyThresholdGrams(mixed $raw): int|false|null
    {
        $kg = OneCNumber::parse($raw);

        if ($kg === null) {
            return null;
        }

        if ($kg === false) {
            return false;
        }

        $grams = (int) round($kg * 1000);

        return $grams < 0 || $grams > 4294967295 ? false : $grams;
    }

    /**
     * Сбросить кеш доступных магазинов после изменения данных магазина.
     *
     * Why: storefront хранит ответ slot-api в Redis per-address на час.
     * Без сброса правка min_order_price/delivery_price/free_delivery_from в зоне
     * подхватится только после истечения TTL — пользователь видит старое значение
     * в плашке «Минимальная сумма заказа».
     */
    private function invalidateAvailableStoresCache(): void
    {
        // Переключение ignore_stock в админке должно сразу влиять на поиск.
        \App\Services\Search\SearchService::forgetIgnoreStockStoresCache();

        try {
            app(AvailableStoreService::class)->invalidateAllAddressCaches();
        } catch (\Throwable $e) {
            Log::warning('Failed to invalidate available stores cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Резолв city_id: city_name из события → населённый пункт из address.
     * Неизвестный город создаётся (см. CityResolver).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCityId(array $data): ?int
    {
        $city = app(CityResolver::class)->resolveByNameOrAddress(
            isset($data['city_name']) ? (string) $data['city_name'] : null,
            isset($data['address']) ? (string) $data['address'] : null,
        );

        return $city?->id;
    }

    /**
     * Синхронизация расписания работы.
     * Обёрнуто в транзакцию для атомарности delete/create.
     *
     * @param  array<string, array{start: string, end: string}|null>  $schedule
     */
    private function syncSchedule(Store $store, array $schedule): void
    {
        if (empty($schedule)) {
            return;
        }

        DB::transaction(function () use ($store, $schedule) {
            // Удаляем старое расписание
            $store->schedules()->delete();

            // Создаём новое
            foreach ($schedule as $day => $hours) {
                $dayNumber = self::DAY_MAP[$day] ?? null;
                if ($dayNumber === null) {
                    continue;
                }

                if ($hours === null) {
                    // Выходной — запись без времени
                    StoreSchedule::create([
                        'store_id' => $store->id,
                        'day_of_week' => $dayNumber,
                        'opens_at' => null,
                        'closes_at' => null,
                    ]);
                } else {
                    StoreSchedule::create([
                        'store_id' => $store->id,
                        'day_of_week' => $dayNumber,
                        'opens_at' => $hours['start'] ?? null,
                        'closes_at' => $hours['end'] ?? null,
                    ]);
                }
            }
        });
    }

    /**
     * Скачать и сохранить изображение магазина.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImage(Store $store, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (! $imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'stores');

            $store->update(['image_path' => $localPath]);

            Log::info('Store image downloaded', [
                'store_id' => $store->id,
                'image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download store image', [
                'store_id' => $store->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Поиск существующего магазина по code.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        return Store::withTrashed()
            ->where('code', $eventData['shop_code'])
            ->first();
    }
}
