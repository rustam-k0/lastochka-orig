<?php

namespace App\Transformers;

use App\Models\City;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Services\CityResolver;
use App\Services\ModuleConfigService;
use App\Services\OneC\OneCWeekSchedule;
use App\Support\OneCBool;
use App\Support\OneCInt;
use App\Support\OneCNumber;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
        'shop_code',
        'source',
    ];

    /**
     * Map internal day constants to event keys
     */
    protected const DAYS_MAP = [
        Type::MONDAY => 'monday',
        Type::TUESDAY => 'tuesday',
        Type::WEDNESDAY => 'wednesday',
        Type::THURSDAY => 'thursday',
        Type::FRIDAY => 'friday',
        Type::SATURDAY => 'saturday',
        Type::SUNDAY => 'sunday',
    ];

    /**
     * Map event keys to internal day constants
     */
    protected const EVENT_DAYS_MAP = [
        'monday' => Type::MONDAY,
        'tuesday' => Type::TUESDAY,
        'wednesday' => Type::WEDNESDAY,
        'thursday' => Type::THURSDAY,
        'friday' => Type::FRIDAY,
        'saturday' => Type::SATURDAY,
        'sunday' => Type::SUNDAY,
    ];

    /**
     * Атрибуты, которые fromEvent() выставляет всегда, с дефолтом при отсутствии ключа
     * (атрибут => ключ события). Для события 1С при модуле onec_shops_master (MID-306)
     * отсутствующий ключ не должен затирать значение, настроенное в админке.
     */
    protected const ONEC_ALWAYS_SET_ATTRIBUTES = [
        'title' => 'title',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'slot_time' => 'slot_time',
        'is_bonus_enabled' => 'is_bonus_enabled',
        'bonus_earn_percent' => 'bonus_earn_percent',
        'bonus_spend_percent' => 'bonus_spend_percent',
    ];

    /**
     * @param Shop $shop
     */
    public function toEvent(Model $shop, ?object $relatedModel = null, ?string $eventId = null): array
    {
        // Получаем основную зону доставки (первую или единственную)
        $primaryZone = $this->getPrimaryDeliveryZone($shop);

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $shop->updated_at ?? now(),
            'id' => (string) $shop->id,
            'shop_code' => $shop->code ?? ('shop_' . $shop->id), // Shop сам себе: используем свой code
            'source' => config('rabbitmq-events.source'),
            'active' => $shop->status_id === \App\Models\Status::CODE_ACTIVE,
            'title' => $shop->title,
            'description' => $shop->description,
            'type' => $shop->type_id === Type::SHOP_TYPE_CAFE ? 'restaurant' : 'shop',
            'city_id' => $shop->city_id,
            'city_name' => $shop->city?->name,
            'is_default' => (bool) $shop->is_default,
            // Адрес магазина (MENDEV-16): без этого поля витрина никогда не
            // получала адрес — stores.address оставался null
            'address' => $shop->address,
            'latitude' => (float) $shop->latitude,
            'longitude' => (float) $shop->longitude,
            'slot_time' => (int) $shop->slot_time,
            'open_24_7' => $shop->getWorkType() === \App\Models\Type::WORK_ALL_DAY,
            'schedule' => $this->transformSchedule($shop),
            'image_url' => $this->getShopImageUrl($shop),
            'delivery_price' => $primaryZone?->delivery_price,
            'min_order_price' => $primaryZone?->min_order_price,
            'free_delivery_from' => $primaryZone?->free_delivery_from,
            // Ступени стоимости доставки основной зоны (MID-280): [] — ступеней нет.
            // Ключ ведёт админка, от 1С его не ждём (контракт событий магазина).
            //
            // Флаг delivery_price_ranges здесь НЕ читается (фикс-раунд 1, решение
            // координатора): событие несёт ступени всегда, гейт только на чтении —
            // на витрине (лесенка игнорирует ступени при выключенном модуле, API
            // отдаёт пустое). Иначе при выключении модуля stores.delivery_price_ranges
            // на витрине обнулился бы, а при обратном включении ступени не вернулись
            // бы, пока каждую зону не пересохранят вручную.
            'delivery_price_ranges' => $primaryZone ? $primaryZone->deliveryPriceRangesList() : [],
            'delivery_type' => $primaryZone?->delivery_type ?? 'own',
            'is_b2b' => (bool) ($shop->is_b2b ?? false),
            'ignore_stock' => (bool) ($shop->ignore_stock ?? false),
            'is_bonus_enabled' => (bool) $shop->is_bonus_enabled,
            'bonus_earn_percent' => (int) ($shop->bonus_earn_percent ?? 0),
            'bonus_spend_percent' => (int) ($shop->bonus_spend_percent ?? 0),
            'is_pickup_enabled' => (bool) ($shop->is_pickup_enabled ?? false),
            'min_pickup_order_amount' => $shop->min_pickup_order_amount,
            'heavy_order_threshold' => $shop->heavy_order_threshold,
            'assembly_price' => $shop->assembly_price,
            'pickup_payment_methods' => $shop->pickup_payment_methods ?? ['card'],
            'pickup_auto_cancel_enabled' => (bool) ($shop->pickup_auto_cancel_enabled ?? true),
            'pickup_wait_perishable_hours' => (int) ($shop->pickup_wait_perishable_hours ?? 1),
            'pickup_wait_regular_hours' => (int) ($shop->pickup_wait_regular_hours ?? 3),
            'pickup_grace_period_hours' => (int) ($shop->pickup_grace_period_hours ?? 1),
            'custom_checkboxes' => json_decode($shop->custom_checkboxes ?: '{}', true) ?: [],
            'created_at' => $shop->created_at,
            'updated_at' => $shop->updated_at,
            'deleted_at' => $shop->deleted_at,
        ];
    }

    public function fromEvent(array $data): array
    {
        // Use shop_code as the unique code for the shop
        // This ensures products with the same shop_code will find this shop
        $shopCode = $data['shop_code'] ?? null;
        $source = $data['source'] ?? 'unknown';
        $externalId = $data['id'] ?? Str::random(8);

        // Prefer shop_code, fallback to source_id format
        $code = $shopCode ?: ($source . '_' . $externalId);

        // Map data to model attributes
        $attributes = [
            'code' => $code,
            'title' => $data['title'] ?? $shopCode,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'slot_time' => $data['slot_time'] ?? 60,
//            'status_id' => ($data['active'] ?? true) ? \App\Models\Status::CODE_ACTIVE : \App\Models\Status::CODE_NOT_PUBLISHED,
            'is_bonus_enabled'   => $data['is_bonus_enabled'] ?? false,
            'bonus_earn_percent' => (int) ($data['bonus_earn_percent'] ?? 0),
            'bonus_spend_percent' => (int) ($data['bonus_spend_percent'] ?? 0),
        ];

        // Магазин по умолчанию: conditional set — событие без ключа не должно
        // сбрасывать флаг, выставленный локально в админке.
        if (array_key_exists('is_default', $data)) {
            $attributes['is_default'] = (bool) $data['is_default'];
        }

        // Адрес магазина: 1С публикует shop.created/updated с адресом строкой —
        // раньше поле не читалось вовсе, и магазины из 1С висели в админке без
        // адреса. Conditional set: событие без ключа адрес не затирает.
        if (array_key_exists('address', $data)) {
            $attributes['address'] = $data['address'];
        }

        // Город: явный city_id (id в cities курьерки, по контракту) → иначе
        // city_name / населённый пункт из адреса, с автосозданием города.
        // Ключ не трогаем, если определить город нечем — раньше city_id
        // безусловно ставился в null и затирал выставленный в админке город.
        $cityId = $this->resolveCityId($data);
        if ($cityId !== null) {
            $attributes['city_id'] = $cityId;
        }

        // MID-306: событие 1С при модуле «1С ведёт магазины и сотрудников» —
        // статус, контакты, площадь, описание, порог тяжёлого заказа.
        if ($this->isOneCShopsMasterEvent($data)) {
            $attributes = array_merge($attributes, $this->oneCShopAttributes($data));
        }

        return $attributes;
    }

    /**
     * ID города курьерки для события магазина.
     *
     * Приоритет: существующий city_id из payload → city_name → адрес.
     */
    protected function resolveCityId(array $data): ?int
    {
        $explicit = $data['city_id'] ?? null;
        if ($explicit !== null && $explicit !== '' && City::query()->whereKey((int) $explicit)->exists()) {
            return (int) $explicit;
        }

        $city = app(CityResolver::class)->resolveByNameOrAddress(
            isset($data['city_name']) ? (string) $data['city_name'] : null,
            isset($data['address']) ? (string) $data['address'] : null,
        );

        return $city?->id;
    }

    /**
     * Find existing shop by code to link with external entity
     * This prevents duplicates when shop already exists locally
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $code = $eventData['shop_code'] ?? null;

        if ($code) {
            $existing = Shop::where('code', $code)->first();
            if ($existing) {
                \Log::info('🔗 Found existing shop by code', [
                    'code' => $code,
                    'local_id' => $existing->id,
                    'external_id' => $eventData['id'] ?? 'unknown',
                ]);
                return $existing;
            }
        }

        return null;
    }

    /**
     * MID-306: для события 1С при модуле onec_shops_master отбрасывает невалидные значения
     * и «магазин по умолчанию» без города. Остальные события — без изменений.
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (!$this->isOneCShopsMasterEvent($eventData)) {
            return $transformedData;
        }

        return $this->sanitizeOneCAttributes($eventData, $transformedData, null);
    }

    /**
     * MID-306: для события 1С при модуле onec_shops_master ключ, которого нет в событии,
     * не затирает поле (правило полей). Остальные события — без изменений.
     */
    public function beforeUpdate(Model $shop, array $eventData, array $transformedData): ?array
    {
        if (!$this->isOneCShopsMasterEvent($eventData)) {
            return $transformedData;
        }

        foreach (self::ONEC_ALWAYS_SET_ATTRIBUTES as $attribute => $key) {
            if (!array_key_exists($key, $eventData)) {
                unset($transformedData[$attribute]);
            }
        }

        return $this->sanitizeOneCAttributes($eventData, $transformedData, $shop);
    }

    /**
     * Событие 1С (source и event_source = 1c) при включённом модуле
     * «1С ведёт магазины и сотрудников». Повторная публикация магазина курьеркой
     * (event_source = admin) сюда не попадает.
     */
    protected function isOneCShopsMasterEvent(array $eventData): bool
    {
        $source = $eventData['source'] ?? null;

        return $source === '1c'
            && ($eventData['event_source'] ?? $source) === '1c'
            && ModuleConfigService::isOneCShopsMasterEnabled();
    }

    /**
     * Новые ключи магазина из 1С (MID-306). Невалидное значение не попадает в атрибуты.
     */
    private function oneCShopAttributes(array $data): array
    {
        $attributes = [];

        if (array_key_exists('active', $data)) {
            [, $active] = OneCBool::parse($data['active']);

            if ($active === null) {
                $this->warnOneC('active is empty or invalid, shop status not changed', $data);
            } else {
                $attributes['status_id'] = $active ? Status::CODE_ACTIVE : Status::CODE_NOT_PUBLISHED;
            }
        }

        if (array_key_exists('phone', $data)) {
            $phone = $this->oneCText($data['phone']);

            if ($phone !== null && mb_strlen($phone) > 255) {
                $this->warnOneC('phone is longer than 255 chars, shop phone not changed', $data);
            } else {
                $attributes['phone'] = $phone;
            }
        }

        if (array_key_exists('email', $data)) {
            $email = $this->oneCText($data['email']);

            if ($email !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $this->warnOneC('email is invalid, shop email not changed', $data);
            } else {
                $attributes['email'] = $email;
            }
        }

        if (array_key_exists('area_sqm', $data)) {
            $area = OneCNumber::parse($data['area_sqm']);

            if ($area === false || ($area !== null && ($area < 0 || $area > 999999.99))) {
                $this->warnOneC('area_sqm is invalid, shop area not changed', $data);
            } else {
                $attributes['area_sqm'] = $area === null ? null : round($area, 2);
            }
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $this->oneCText($data['description']);
        }

        if (array_key_exists('heavy_order_threshold_kg', $data)) {
            $kg = OneCNumber::parse($data['heavy_order_threshold_kg']);
            $grams = is_float($kg) ? (int) round($kg * 1000) : null;

            if ($kg === false || ($grams !== null && ($grams < 0 || $grams > 4294967295))) {
                $this->warnOneC('heavy_order_threshold_kg is invalid, threshold not changed', $data);
            } else {
                $attributes['heavy_order_threshold'] = $grams;
            }
        }

        return $attributes;
    }

    /**
     * NOT NULL поля (title, slot_time, бонусы) не очищаются и не принимают мусор;
     * координаты: пустое значение очищает, мусор и выход за пределы не меняют;
     * «магазин по умолчанию» без города не ставится.
     */
    private function sanitizeOneCAttributes(array $eventData, array $attributes, ?Model $shop): array
    {
        if ($shop !== null && array_key_exists('title', $eventData) && $this->oneCText($eventData['title']) === null) {
            unset($attributes['title']);
            $this->warnOneC('title is empty, shop title not changed', $eventData);
        }

        if (array_key_exists('slot_time', $eventData)
            && filter_var($eventData['slot_time'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            unset($attributes['slot_time']);
            $this->warnOneC('slot_time is empty or invalid, slot not changed', $eventData);
        }

        if (array_key_exists('is_bonus_enabled', $eventData)) {
            [, $enabled] = OneCBool::parse($eventData['is_bonus_enabled']);

            if ($enabled === null) {
                unset($attributes['is_bonus_enabled']);
                $this->warnOneC('is_bonus_enabled is empty or invalid, not changed', $eventData);
            } else {
                $attributes['is_bonus_enabled'] = $enabled;
            }
        }

        foreach (['bonus_earn_percent', 'bonus_spend_percent'] as $key) {
            if (!array_key_exists($key, $eventData)) {
                continue;
            }

            [, $percent] = OneCInt::parse($eventData[$key], 0, 100);

            if ($percent === null) {
                unset($attributes[$key]);
                $this->warnOneC("{$key} is empty or not an integer 0..100, not changed", $eventData);
            } else {
                $attributes[$key] = $percent;
            }
        }

        foreach (['latitude' => 90, 'longitude' => 180] as $key => $limit) {
            if (!array_key_exists($key, $eventData)) {
                continue;
            }

            $coordinate = OneCNumber::parse($eventData[$key]);

            if ($coordinate === false || ($coordinate !== null && abs($coordinate) > $limit)) {
                unset($attributes[$key]);
                $this->warnOneC("{$key} is invalid, not changed", $eventData);
            } else {
                $attributes[$key] = $coordinate;
            }
        }

        if (($attributes['is_default'] ?? false) === true) {
            $cityId = $attributes['city_id'] ?? $shop?->city_id;

            if (empty($cityId)) {
                unset($attributes['is_default']);
                $this->warnOneC('is_default ignored: shop city is unknown', $eventData);
            }
        }

        return $attributes;
    }

    /**
     * Строка из события 1С: trim, пустая → null; не строка и не число → null.
     */
    private function oneCText(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function warnOneC(string $message, array $eventData): void
    {
        Log::warning('1C shop event: ' . $message, [
            'event_id' => $eventData['event_id'] ?? null,
            'shop_code' => $eventData['shop_code'] ?? null,
        ]);
    }

    public function afterCreate(Model $shop, array $eventData): void
    {
        if ($this->isOneCShopsMasterEvent($eventData)) {
            $this->applyOneCSchedule($shop, $eventData);
            $this->resetOtherDefaultShops($shop, $eventData);

            return;
        }

        $this->syncSchedule($shop, $eventData['schedule'] ?? []);
    }

    public function afterUpdate(Model $shop, array $eventData): void
    {
        if ($this->isOneCShopsMasterEvent($eventData)) {
            $this->applyOneCSchedule($shop, $eventData);
            $this->resetOtherDefaultShops($shop, $eventData);

            return;
        }

        $this->syncSchedule($shop, $eventData['schedule'] ?? []);
    }

    /**
     * MID-306: в городе не более одного магазина по умолчанию (как ShopService::syncDefaultShop).
     * Хуки after* выполняются вне withoutEvents, поэтому прежний дефолт сбрасывается поштучно
     * через Eloquent: observer кладёт shop.updated в outbox, и флаг снимается на витрине.
     */
    protected function resetOtherDefaultShops(Model $shop, array $eventData): void
    {
        if (empty($eventData['is_default'])) {
            return;
        }

        $shop = $shop->fresh() ?? $shop;

        if (!$shop->is_default || empty($shop->city_id)) {
            return;
        }

        Shop::query()
            ->where('city_id', $shop->city_id)
            ->where('id', '!=', $shop->id)
            ->where('is_default', true)
            ->get()
            ->each(fn (Shop $other) => $other->update(['is_default' => false]));
    }

    protected function transformSchedule(Shop $shop): array
    {
        $schedule = [];
        // Ensure workingHours are loaded
        if (!$shop->relationLoaded('workingHours')) {
            $shop->load('workingHours');
        }

        foreach ($shop->workingHours as $hour) {
            $dayKey = self::DAYS_MAP[$hour->day_of_week] ?? null;
            if ($dayKey) {
                $schedule[$dayKey] = [
                    'start' => $this->formatTime($hour->opening_time),
                    'end' => $this->formatTime($hour->closing_time),
                ];
            }
        }

        // Convert array to object as per requirement "schedule: object"
        return $schedule;
    }

    protected function syncSchedule(Shop $shop, array|object $scheduleData): void
    {
        $scheduleData = (array) $scheduleData;

        // Assuming we wipe and recreate or update?
        // Existing logic in SetShopHours used delete/create or update.
        // We need to update workingHours (Type::HOLIDAY_DELIVERY based on previous analysis)

        $idsToKeep = [];

        foreach (self::EVENT_DAYS_MAP as $key => $dayId) {
            if (isset($scheduleData[$key])) {
                $time = $scheduleData[$key];
                // Validate time structure
                if (!isset($time['start']) || !isset($time['end'])) {
                    continue;
                }

                $hour = $shop->workingHours()->where('day_of_week', $dayId)->first()
                    ?? $shop->workingHours()->make(['day_of_week' => $dayId]);

                $hour->opening_time = $time['start'];
                $hour->closing_time = $time['end'];
                $hour->type_id = Type::HOLIDAY_DELIVERY; // Explicitly set type? Shop::workingHours() uses whereTypeId ? No, relationships in Shop.php don't filter by type in workingHours() method??

                // Wait, let's check Shop.php again.
                // public function workingHours() { return $this->hasMany(ShopWorkingHour::class)->orderBy('day_of_week'); }
                // ShopWorkingHour has 'type_id'.
                // But SetShopHours.php does: $shop->workingHours()->whereDayOfWeek($day)...

                // We should set type_id. What is the default type for workingHours?
                // SetShopHours logic:
                // Type::HOLIDAY_DELIVERY => $shop->workingHours()...

                // Wait, ShopWorkingHour has type_id.
                // Does ShopWorkingHour relation filter by type_id?
                // No: return $this->hasMany(ShopWorkingHour::class)->orderBy('day_of_week');

                // So workingHours() returns ALL ShopWorkingHour records.
                // But SetShopHours treats Type::HOLIDAY_DELIVERY as workingHours().

                // If I assume this transformer is for "Delivery Schedule", I should probably stick to Type::HOLIDAY_DELIVERY ?
                // But Shop.php has workingHours() which returns ALL.
                // Let's check if ShopWorkingHour is used for anything else.

                $hour->type_id = $hour->type_id ?? Type::HOLIDAY_DELIVERY; // Default to delivery if not set
                $hour->save();
                $idsToKeep[] = $hour->id;
            }
        }

        // Optional: remove days not in schedule?
        // If schedule is partial update, maybe not.
        // But usually schedule sync is full.
    }

    /**
     * MID-306: график магазина из 1С (модуль onec_shops_master).
     *
     * - schedule невалиден → график не меняется (даже при open_24_7 = true);
     * - open_24_7 = true → круглосуточно все 7 дней, дни schedule не учитываются;
     * - schedule пришёл (open_24_7 не true) → дни с интервалом «по графику», остальные строки удаляются;
     *   schedule null или {} → все строки удаляются;
     * - open_24_7 = false без schedule → удаляются только круглосуточные строки;
     * - нет ни schedule, ни распознанного open_24_7 (ключа нет, null, "", мусор) → график не меняется.
     */
    protected function applyOneCSchedule(Model $shop, array $eventData): void
    {
        $hasSchedule = array_key_exists('schedule', $eventData);
        $allDay = $this->oneCAllDay($eventData);

        if (!$hasSchedule && $allDay === null) {
            return;
        }

        $days = $hasSchedule ? OneCWeekSchedule::parse($eventData['schedule']) : [];

        if ($days === false) {
            $this->warnOneC('schedule is invalid, working hours not changed', $eventData);

            return;
        }

        $workingDays = array_filter($days, static fn ($interval) => $interval !== null);
        $weekDays = Type::getWeekDays();

        if ($allDay === true) {
            if ($days !== [] && count($workingDays) < count($weekDays)) {
                $this->warnOneC('open_24_7 applies to all days, schedule days ignored', $eventData);
            }

            $this->replaceWorkingHours($shop, array_fill_keys($weekDays, null), Type::WORK_ALL_DAY);

            return;
        }

        if (!$hasSchedule) {
            $shop->workingHours()->where('type_id', Type::WORK_ALL_DAY)->delete();

            return;
        }

        $this->replaceWorkingHours($shop, $workingDays, Type::WORK_SCHEDULE);
    }

    /**
     * open_24_7 из события 1С: true/false; null — ключа нет, пустое значение или мусор (мусор — warning).
     */
    private function oneCAllDay(array $eventData): ?bool
    {
        if (!array_key_exists('open_24_7', $eventData)) {
            return null;
        }

        [$valid, $allDay] = OneCBool::parse($eventData['open_24_7']);

        if (!$valid) {
            $this->warnOneC('open_24_7 is invalid, ignored', $eventData);
        }

        return $allDay;
    }

    /**
     * Строки обновляются по day_of_week (как SetShopWorkingHours), лишние удаляются.
     *
     * @param array<int, array{start: string, end: string}|null> $days
     */
    private function replaceWorkingHours(Model $shop, array $days, int $typeId): void
    {
        $keepIds = [];

        foreach ($days as $day => $interval) {
            $hour = $shop->workingHours()->where('day_of_week', $day)->first()
                ?? $shop->workingHours()->make(['day_of_week' => $day]);

            $hour->type_id = $typeId;
            $hour->opening_time = $interval['start'] ?? null;
            $hour->closing_time = $interval['end'] ?? null;
            $hour->save();

            $keepIds[] = $hour->id;
        }

        $shop->workingHours()->whereNotIn('id', $keepIds)->delete();
    }

    private function formatTime($time): ?string
    {
        // У круглосуточного дня времени нет: Carbon::parse(null) вернул бы текущее время (MID-306).
        if ($time === null || $time === '') {
            return null;
        }

        return \Carbon\Carbon::parse($time)->format('H:i');
    }

    /**
     * Получить URL изображения магазина (логотип)
     */
    protected function getShopImageUrl(Shop $shop): ?string
    {
        // Загружаем связь если не загружена
        if (!$shop->relationLoaded('img')) {
            $shop->load('img');
        }

        $path = $shop->img?->path;

        if (!$path) {
            return null;
        }

        return PathNormalizer::url($path);
    }

    /**
     * Получить основную зону доставки магазина.
     *
     * Возвращает первую АКТИВНУЮ зону (is_active). Если активных зон нет —
     * null (как когда зон нет вообще). Иначе деактивированная зона продолжала бы
     * утаскивать свои delivery_price/min_order_price/free_delivery_from в storefront.
     */
    protected function getPrimaryDeliveryZone(Shop $shop): ?\App\Models\ShopZone
    {
        // Загружаем связь если не загружена
        if (!$shop->relationLoaded('zones')) {
            $shop->load('zones');
        }

        return $shop->zones->firstWhere('is_active', true);
    }
}
