<?php

namespace App\Models;

use App\Models\Scopes\NotFinished;
use App\Services\ShopService;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;


/**
 * @mixin IdeHelperOrder
 */
class Order extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'status_id',
    'shop_id',
    'amount',
    'yandex_claim_id',
    'yandex_delivery_status',
    'yandex_courier_name',
    'yandex_courier_phone',
    'yandex_delivery_price',
    'operator_comment',
    'discount',
    'discount_percent',
    'promocode_code',
    'promocode_type',
    'promocode_value',
    'items_subtotal',
    'bonus_spent',
    'bonus_earned',
    'has_card',
    'card_number',
    'parent_order_id',
    'sub_number',
    'assembly_zone_id',
    'slot_start_date',
    'slot_end_date',
    'payment_type_id',
    'client_id',
    'delivery_price',
    'delivery_price_snapshot',
    'assembly_cost',
    'total_weight',
    'is_weight_partial',
    'pickup_ready_at',
    'picked_up_at',
    'picked_up_by_user_id',
    'is_heavy',
    'couriers_count',
    'finished_at',
    'paid',
    'paid_at',
    'payment_held_at',
    'payment_status',
  ];

  protected $casts = [
    'slot_start_date' => 'datetime',
    'slot_end_date' => 'datetime',
    'payment_held_at' => 'datetime',
    'warning_time' => 'datetime',
    'is_weight_partial' => 'boolean',
    'is_heavy' => 'boolean',
    'finished_at' => 'datetime',
    'pickup_ready_at' => 'datetime',
    'picked_up_at' => 'datetime',
    // Опции чекаута (MENDEV-17): снапшот [{code, label}] из витрины
    'checkout_options' => 'array',
    // Снимок условий доставки при оформлении (MID-280): {base_price, ranges, checkout_price}
    'delivery_price_snapshot' => 'array',
  ];

  public function orderItems(): ?HasMany
  {
    $relation = $this->hasMany(OrderItem::class);

    $user = auth()->user();
    if ($user && $user->hasRole(Role::COURIER)) {
      $relation->where('status_id', '!=', Status::ORDER_ITEM_CANCELED);
    }

    return $relation;
  }

  public function assemblies(): HasMany
  {
    return $this->hasMany(Assembly::class);
  }

  public function assembly(): HasOne
  {
    return $this->hasOne(Assembly::class);
  }

  public function packages(): HasMany
  {
    return $this->hasMany(AssemblyPackage::class);
  }

  public function deliveryOrders(): HasMany
  {
    return $this->hasMany(DeliveryOrder::class);
  }

  public function clientInfo(): HasOne
  {
    return $this->hasOne(ClientOrderInfo::class);
  }

  //Поиск по заказам, которые находятся в сборке
  public function scopeAuth($query, $args): void
  {
    if (isset($args['status']) && $args['status'] == Status::ORDER_ASSEMBLED) {
      $query->whereHas('assembly', function ($query) {
        $query->withoutGlobalScope(new NotFinished())->auth();
      });
      return;
    }

    $query->whereHas('assembly',fn($query)=>$query->auth());
  }

  //Поиск по заказам, которые находятся на доставке
  public function scopeWithDelivery($query): void
  {
    $query->whereHas('deliveryOrders.delivery');
  }

  public function scopeCurrentShop($query)
  {
    return ShopService::bindShopQuery($query);
  }

  /**
   * Самовывоз курьеру не назначается. По умолчанию исключаем pickup из выдачи;
   * исключение — пользователь с ролью COLLECTOR (сборщику pickup нужны для сборки).
   * При включённом модуле «Самовывоз без сборщиков» pickup скрывается и от
   * сборщиков — такие заказы обрабатываются на стороне 1С.
   */
  public function scopeExcludePickup($query)
  {
    $user = auth()->user();
    if ($user && $user->hasRole(Role::COLLECTOR) && !self::isPickupSkipCollectorsModuleEnabled()) {
      return $query;
    }

    return $query->where('type_id', '!=', Type::DELIVERY_PICKUP);
  }

  /**
   * Фильтр для сборщика
   * При статусе "собран" возвращает также заказы "в доставке" и "доставлен"
   */
  public function scopeCollectorFilter($query, $args): mixed
  {
    if (isset($args['status']) && $args['status'] == Status::ORDER_ASSEMBLED) {
      $query->whereIn('status_id', [
        Status::ORDER_ASSEMBLED,
        Status::ORDER_IN_DELIVERY,
        Status::ORDER_FINISHED,
      ]);
      // Убираем status из args, чтобы базовый filter его не обрабатывал повторно
      unset($args['status']);
    }

    return $this->scopeFilter($query, $args);
  }

  public function scopeDaily($query)
  {
    $query->where(function ($query) {
      $query->where(function ($query) {
        $query->where('status_id', Status::ORDER_CREATED)
          ->where(function ($query) {
            // B2B-клиенты оформляют заказы на будущие даты — сборщик B2B-магазина
            // должен видеть их заранее для подготовки на складе (ALADDIN-13553)
            $query->where('slot_start_date', '<=', now()->endOfDay())
              ->orWhereRelation('shop', 'is_b2b', true);
          });
      })
        ->orWhere(function ($query) {
          // Доставленные: слот на сегодня либо факт доставки сегодня —
          // заказ, доставленный с опозданием по вчерашнему слоту, не должен
          // пропадать из выдачи сразу после доставки (LASTOCHKI-JAMI-11141).
          // Факт доставки — finished_at, НЕ updated_at: иначе любое касание
          // давно доставленного заказа (отметка оплаты, правка в админке, sync)
          // возвращало его сборщику во вкладку «Собрано» с отрицательным таймером.
          $query->where('status_id', Status::ORDER_FINISHED)
            ->where(function ($query) {
              $query->where('slot_start_date', '>=', now()->startOfDay())
                ->orWhere('finished_at', '>=', now()->startOfDay());
            });
        })
        ->orWhereNotIn('status_id', [Status::ORDER_CREATED, Status::ORDER_FINISHED]);
    });
  }

  /**
   * Порядок выдачи списков заказов сотрудникам: по началу слота доставки,
   * при равных слотах — по поступлению заказа в курьерку.
   *
   * Без явной сортировки MySQL отдаёт строки в порядке id, то есть в порядке
   * оформления: на проде 17.09 заказ № 306 со слотом 19:30–20:00 стоял у курьера
   * ниже № 303 со слотом 20:00–20:30, потому что 303 оформили на три часа раньше.
   * Сортировка нужна и в режиме без слотов (checkout_without_slots): там в колонке
   * лежит момент поступления заказа, то есть порядок остаётся прежним, FIFO.
   *
   * Заказы без слота (колонка nullable) уходят в конец списка: при сортировке
   * MySQL по возрастанию NULL иначе поднялся бы наверх.
   */
  public function scopeOrderBySlot($query): void
  {
    $query->orderByRaw('orders.slot_start_date IS NULL')
      ->orderBy('orders.slot_start_date')
      ->orderBy('orders.id');
  }

  /**
   * Фильтр по роли: сборщик видит подзаказы, курьер — только родительские.
   */
  public function scopeByRole($query): void
  {
    $user = auth()->user();
    if ($user && $user->hasRole(Role::COURIER)) {
      $this->scopeOnlyParentOrStandalone($query);
    } else {
      $this->scopeOnlyAssemblable($query);
    }
  }

  /**
   * Для сборщика: показывать подзаказы (если они есть) или обычные заказы.
   * Скрывает родительские заказы у которых есть дочерние.
   */
  public function scopeOnlyAssemblable($query): void
  {
    $query->where(function ($q) {
      $q->whereNotNull('parent_order_id')
        ->orWhere(function ($q2) {
          $q2->whereNull('parent_order_id')
            ->whereDoesntHave('childOrders');
        });
    });
  }

  /**
   * Только родительские и обычные заказы (без подзаказов).
   * Для курьера и общего списка заказов.
   */
  public function scopeOnlyParentOrStandalone($query): void
  {
    $query->whereNull('parent_order_id');
  }

  /**
   * Модуль «Назначение курьера сборщиком» (courier_assignment): курьер видит
   * только заказы, назначенные ему (через его Delivery, включая завершённые
   * рейсы — доставленные сегодня остаются видимыми, как в scopeDaily).
   * При выключенном модуле и для остальных ролей — no-op.
   */
  public function scopeByCourierAssignment($query): void
  {
    if (!self::isCourierAssignmentModuleEnabled()) {
      return;
    }

    $user = auth()->user();
    if (!$user || !$user->hasRole(Role::COURIER)) {
      return;
    }

    $query->whereHas('deliveryOrders', function ($q) use ($user) {
      $q->whereHas('delivery', function ($q) use ($user) {
        $q->withoutGlobalScope(new NotFinished())->whereUserId($user->id);
      });
    });
  }

  /**
   * Фильтр заказов сборщика по назначенной ему зоне сборки.
   *
   * Зона резолвится для магазина текущей смены сборщика на сегодня
   * (привязка на дату приоритетнее постоянной). Если зона не назначена —
   * сборщик видит все заказы (no-op). Не-сборщикам фильтр не применяется.
   */
  public function scopeByAssemblyZone($query): void
  {
    // Гейт по модулю: если «Зоны сборки» выключены, заказы не расщепляются
    // (splitOrderByZones no-op) — фильтрация по зоне бессмысленна и привела бы
    // к пустому списку при остаточных привязках. Поведение синхронно с admin_back.
    if (!self::isAssemblyZonesModuleEnabled()) {
      return;
    }

    $user = auth()->user();
    if (!$user || !$user->hasRole(Role::COLLECTOR)) {
      return;
    }

    $shift = $user->shift; // текущая незавершённая смена (GlobalScope NotFinished)
    if (!$shift) {
      return;
    }

    $zoneId = $user->resolveAssemblyZoneId((int) $shift->shop_id);
    if ($zoneId === null) {
      return;
    }

    $query->where('assembly_zone_id', $zoneId);
  }

  /**
   * Включён ли модуль «Зоны сборки» в admin_settings.project_modules.
   * Читаем общую БД напрямую (в delivery_back нет модели AdminSetting).
   */
  public static function isAssemblyZonesModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['assembly_zones']['enabled'] ?? false);
  }

  /**
   * Включён ли модуль «Тяжёлые заказы» в admin_settings.project_modules.
   * Читаем общую БД напрямую (зеркало isAssemblyZonesModuleEnabled).
   */
  public static function isHeavyOrdersModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['heavy_orders']['enabled'] ?? false);
  }

  /**
   * Включён ли модуль «Назначение курьера сборщиком»
   * (courier_assignment в admin_settings.project_modules).
   * Читаем общую БД напрямую (зеркало isHeavyOrdersModuleEnabled).
   */
  public static function isCourierAssignmentModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['courier_assignment']['enabled'] ?? false);
  }

  /**
   * Включён ли модуль «Самовывоз без сборщиков»
   * (pickup_skip_collectors в admin_settings.project_modules).
   * Читаем общую БД напрямую (зеркало isHeavyOrdersModuleEnabled).
   */
  public static function isPickupSkipCollectorsModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['pickup_skip_collectors']['enabled'] ?? false);
  }

  /**
   * Включён ли модуль «Оформление заказа без слотов» (checkout_without_slots,
   * MID-256): заказы с витрины приходят без слота (slot_* = момент оформления
   * по fallback консьюмера). Читаем общую БД напрямую (зеркало isHeavyOrdersModuleEnabled).
   */
  public static function isCheckoutWithoutSlotsModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['checkout_without_slots']['enabled'] ?? false);
  }

  /**
   * Активен ли флоу совместной доставки тяжёлых заказов: признак is_heavy
   * и мультикурьерное назначение работают при модуле heavy_orders ИЛИ
   * courier_assignment (Platrum 12180 — совместная доставка входит в
   * «Назначение курьера сборщиком»).
   */
  public static function isHeavyDeliveryFlowEnabled(): bool
  {
    return self::isHeavyOrdersModuleEnabled() || self::isCourierAssignmentModuleEnabled();
  }

  /**
   * Включён ли модуль «Списание оплаты сборщиком» (collector_payment).
   * Читаем общую БД напрямую (зеркало isHeavyOrdersModuleEnabled).
   */
  public static function isCollectorPaymentModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['collector_payment']['enabled'] ?? false);
  }

  /**
   * Модуль «Честный знак» (honest_sign в project_modules, MID-266): скан кодов
   * маркировки при сборке, проверка в 1С (CheckCIS), гейты ProductConfirm /
   * AssemblyFinish и передача marking_codes в события заказа.
   */
  public static function isHonestSignModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['honest_sign']['enabled'] ?? false);
  }

  /**
   * Модуль «Цены от количества» (price_tiers в project_modules, MID-308).
   *
   * Флаг гейтит только построение НОВЫХ снапшотов лесенки из живой таблицы
   * price_tiers: добавка сборщика, товар-замена. Пересчёт позиции по уже
   * имеющемуся снапшоту order_items.price_tiers от флага не зависит: снапшот
   * приходит с витрины, а её флаг живёт в другой БД и может разойтись с этим.
   *
   * Без мемоизации, как соседние флаги: метод зовут долгоживущие процессы
   * (outbox-воркер, консьюмер очереди), где включение модуля в админке должно
   * долетать без рестарта, и тесты, переключающие флаг внутри одного PHP-процесса.
   * Лишние чтения admin_settings убирает OrderItemTierPricing: один экземпляр
   * сервиса на операцию читает флаг один раз.
   *
   * Любая ошибка чтения = выключено (fail-safe на прежние формулы).
   */
  public static function isPriceTiersModuleEnabled(): bool
  {
    try {
      $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
        ->where('key', 'project_modules')
        ->value('value');

      $modules = $raw ? (json_decode($raw, true) ?: []) : [];

      return (bool) ($modules['price_tiers']['enabled'] ?? false);
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * Состав заказа заблокирован для правок сборщиком: оплата уже списана
   * (модуль collector_payment, онлайн-оплата). После списания менять
   * позиции нельзя — сумма списания перестала бы соответствовать составу.
   */
  public function isCompositionLockedForAssembly(): bool
  {
    return self::isCollectorPaymentModuleEnabled()
      && (int) $this->payment_type_id === \App\Models\Type::PAYMENT_ONLINE
      && (bool) $this->paid;
  }

  public function parentOrder(): BelongsTo
  {
    return $this->belongsTo(Order::class, 'parent_order_id');
  }

  public function childOrders(): HasMany
  {
    return $this->hasMany(Order::class, 'parent_order_id');
  }

  public function assemblyZone(): BelongsTo
  {
    return $this->belongsTo(AssemblyZone::class);
  }

  public function shop()
  {
    return $this->belongsTo(Shop::class);
  }

  public function client(): BelongsTo
  {
    return $this->belongsTo(Client::class);
  }

  /**
   * Число курьеров на доставке для выдачи API.
   *
   * Счётчик денормализован на КОРНЕВОМ заказе (JointDelivery::recalcCouriersCount),
   * а сборщик в своих списках видит подзаказы — без резолва с корня тег
   * «Ожидает курьера» горел бы на заказе с уже назначенным курьером.
   */
  public function resolveCouriersCount(): int
  {
    if ($this->parent_order_id === null) {
      return (int) $this->couriers_count;
    }

    return (int) \App\Support\JointDelivery::rootOrder($this)->couriers_count;
  }

  /** Суммарный вес заказа в граммах: зафиксированный либо расчётный по составу. */
  public function resolveTotalWeight(): ?int
  {
    return \App\Support\OrderWeight::totalWeight($this);
  }

  /** Вес неполный: часть позиций без веса в карточке товара. */
  public function resolveIsWeightPartial(): bool
  {
    return \App\Support\OrderWeight::isWeightPartial($this);
  }

  /** Признак «Тяжёлый заказ» с учётом расчётного веса для заказов мимо витрины. */
  public function resolveIsHeavy(): bool
  {
    return \App\Support\OrderWeight::isHeavy($this);
  }

  /** Единица измерения total_weight — граммы (константа контракта API). */
  public function resolveTotalWeightUnit(): string
  {
    return \App\Support\OrderWeight::UNIT;
  }

  /**
   * Длительность окна доставки в режиме без слотов, минуты (MID-285).
   *
   * Доставка выполняется за 15–30 минут после оформления, поэтому клиенту
   * обещают, а сборщику и курьеру показывают верхнюю границу этого обещания.
   */
  public const DELIVERY_WINDOW_MINUTES = 30;

  /**
   * Начало слота доставки для выдачи API.
   *
   * В режиме без слотов — начало окна доставки, иначе выбранный клиентом слот.
   * @see usesDeliveryWindow()
   */
  public function resolveSlotStartDate(): ?\Illuminate\Support\Carbon
  {
    return $this->usesDeliveryWindow() ? $this->deliveryWindowStart() : $this->slot_start_date;
  }

  /**
   * Окончание слота доставки для выдачи API.
   *
   * В режиме без слотов — конец окна доставки, иначе выбранный клиентом слот.
   * @see usesDeliveryWindow()
   */
  public function resolveSlotEndDate(): ?\Illuminate\Support\Carbon
  {
    if (! $this->usesDeliveryWindow()) {
      return $this->slot_end_date;
    }

    return $this->deliveryWindowStart()?->copy()->addMinutes(self::DELIVERY_WINDOW_MINUTES);
  }

  /**
   * Начало окна доставки — момент холдирования средств клиента (MID-285).
   *
   * Метку присылает витрина: холд живёт в её payment_transactions, в общей
   * БД он известен только как payment_held_at из события заказа. Подзаказ
   * сплита берёт метку родителя — доставляются они одним окном, а собственный
   * created_at подзаказа равен моменту разделения, а не оформления.
   *
   * Fallback на created_at закрывает случаи, где холда нет вовсе (оплата
   * наличными) либо он ещё не подтверждён, а также заказы, оформленные до
   * появления метки. Для заказа, попавшего в курьерку после подтверждения
   * холда (модуль draft_orders_until_payment), это тот же момент с точностью
   * до задержки публикации события.
   */
  protected function deliveryWindowStart(): ?\Illuminate\Support\Carbon
  {
    $heldAt = $this->payment_held_at;

    if ($heldAt === null && $this->parent_order_id !== null) {
      $heldAt = $this->parentOrder()->first()?->payment_held_at;
    }

    return $heldAt ?? $this->created_at;
  }

  /**
   * Показывать ли заказу окно доставки вместо слота.
   *
   * Модуль «Оформление заказа без слотов» (checkout_without_slots): клиент
   * оформляет доставку без выбора времени, а в колонке слота всё равно лежит
   * значение — момент прихода заказа в курьерку по fallback консьюмера
   * (start = end). В приложении оно выглядело временем доставки: у заказа
   * без слота это «20:01–20:01», а у оформленных со слотом до фикса
   * MID-272 — вообще вчерашнее время. До MID-285 слот в этом режиме просто
   * скрывался, теперь на его месте выдаётся окно «холд → +30 минут».
   *
   * Самовывоз в модуль не входит (решение от 20.08): клиент выбирает время
   * выдачи, слот там реальный и на экране нужен.
   *
   * В БД значения остаются нетронутыми — на slot_start_date завязаны
   * scopeDaily (заказ попадает в день сборщика) и сортировка FIFO.
   */
  protected function usesDeliveryWindow(): bool
  {
    if ((int) $this->type_id === Type::DELIVERY_PICKUP) {
      return false;
    }

    // Мемо на процесс: под php-fpm это ровно один запрос на HTTP-запрос,
    // иначе список из 100 заказов дал бы 200 чтений admin_settings.
    // При переезде на Octane/long-running сбрасывать между запросами.
    return self::$checkoutWithoutSlotsMemo ??= self::isCheckoutWithoutSlotsModuleEnabled();
  }

  /** @see usesDeliveryWindow() */
  protected static ?bool $checkoutWithoutSlotsMemo = null;

  /**
   * Опции оформления заказа (MENDEV-17): снапшот [{code, label}] из витрины.
   * Подзаказ split-заказа наследует опции родителя — «Охладить перед
   * доставкой» относится ко всему заказу, а витрина пишет снапшот только
   * в родительский. Битые элементы снапшота отфильтровываются.
   *
   * @return array<int, array{code: string, label: string}>
   */
  public function resolveCheckoutOptions(): array
  {
    $options = $this->checkout_options;

    if (empty($options) && $this->parent_order_id !== null) {
      $options = $this->parentOrder()->first()?->checkout_options;
    }

    if (! is_array($options)) {
      return [];
    }

    return array_values(array_filter($options, static function ($option) {
      return is_array($option) && isset($option['code'], $option['label']);
    }));
  }

  /**
   * Сумма товаров без скидки, бонусов и доставки (для сборщика).
   * amount = товары - discount - bonus_spent + delivery_price
   * subtotal = amount - delivery_price + discount + bonus_spent = чистая сумма товаров
   */
  public function getSubtotalAttribute(): float
  {
    return $this->amount - ($this->delivery_price ?? 0) + ($this->discount ?? 0) + ($this->bonus_spent ?? 0);
  }

  /**
   * Сквозной номер заказа = uuid (id заказа в сторфронте;
   * у подзаказов с суффиксом '-N'). Fallback на внутренний id,
   * если uuid отсутствует (не должен встречаться: колонка NOT NULL).
   */
  public function getNumberAttribute(): string
  {
    if ($this->uuid !== null && $this->uuid !== '') {
      return (string) $this->uuid;
    }

    $parentId = $this->parent_order_id ?? $this->id;

    if ($this->sub_number !== null) {
      return $parentId . '-' . $this->sub_number;
    }

    return (string) $parentId;
  }

  /**
   * Метка заказа — сквозной номер (например '5' или '5-1').
   * Сохранена как поле для обратной совместимости (мобилка сотрудников).
   */
  public function getLabelAttribute(): string
  {
    return $this->number;
  }

  /**
   * Сквозной номер заказа по внутреннему id — для текстов, которые видит
   * сотрудник (push-уведомления): мобилка показывает в шапке заказа именно
   * label, внутренний id ей нужен только для роутинга. Заказ не найден
   * (удалён) — fallback на id, чтобы уведомление не осталось без номера.
   */
  public static function labelById(int $orderId): string
  {
    return static::withTrashed()->find($orderId)?->label ?? (string) $orderId;
  }

}
