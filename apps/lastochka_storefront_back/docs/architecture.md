# Архитектура проекта: Storefront Backend

## Содержание

1. [Обзор](#1-обзор)
2. [Структура директорий](#2-структура-директорий)
3. [Слои приложения](#3-слои-приложения)
4. [Actions](#4-actions)
5. [Services](#5-services)
6. [DTOs](#6-dtos)
7. [Enums](#7-enums)
8. [Models](#8-models)
9. [Controllers](#9-controllers)
10. [Form Requests](#10-form-requests)
11. [API Resources](#11-api-resources)
12. [Policies](#12-policies)
13. [Events & Listeners](#13-events--listeners)
14. [Exceptions](#14-exceptions)
15. [Тестирование](#15-тестирование)
16. [Naming Conventions](#16-naming-conventions)
17. [Чеклист при создании нового модуля](#17-чеклист-при-создании-нового-модуля)

---

## 1. Обзор

Проект использует **гибридную архитектуру Actions + Services**, которая обеспечивает:

- **Тонкие контроллеры** — только валидация и маршрутизация
- **Actions** — одна бизнес-операция = один класс
- **Services** — внешние интеграции и переиспользуемая логика
- **DTOs** — типизированные структуры данных
- **Enums** — строго типизированные перечисления

### Принципы

1. **Single Responsibility** — каждый класс делает одно дело
2. **Dependency Injection** — все зависимости через конструктор
3. **Explicit over Implicit** — явные вызовы вместо магии (observers, model events)
4. **Fail Fast** — валидация на входе, исключения при ошибках
5. **Immutable Data** — DTOs не изменяются после создания

---

## 2. Структура директорий

```
app/
├── Actions/                    # Бизнес-операции
│   ├── Auth/
│   │   ├── SendOtpAction.php
│   │   └── VerifyOtpAction.php
│   ├── Cart/
│   │   ├── AddToCartAction.php
│   │   ├── UpdateCartItemAction.php
│   │   ├── RemoveCartItemAction.php
│   │   ├── ApplyPromocodeAction.php
│   │   └── ClearCartAction.php
│   ├── Order/
│   │   ├── CreateOrderAction.php
│   │   ├── CancelOrderAction.php
│   │   ├── AppendToOrderAction.php
│   │   └── ReserveProductsAction.php
│   ├── Address/
│   │   ├── CreateAddressAction.php
│   │   ├── UpdateAddressAction.php
│   │   └── SetActiveAddressAction.php
│   └── Favorite/
│       └── ToggleFavoriteAction.php
│
├── Services/                   # Интеграции и переиспользуемая логика
│   ├── Delivery/
│   │   ├── DeliverySystemClient.php
│   │   └── DeliveryService.php
│   ├── Sms/
│   │   └── SmsService.php
│   ├── Cart/
│   │   └── CartCalculator.php
│   └── RabbitMQ/
│       ├── RabbitMQPublisher.php
│       └── Handlers/
│           ├── ProductEventHandler.php
│           └── OrderEventHandler.php
│
├── DTOs/                       # Data Transfer Objects
│   ├── Cart/
│   │   ├── CartData.php
│   │   └── CartStoreGroupData.php
│   ├── Delivery/
│   │   ├── DeliverySlotData.php
│   │   └── DeliveryInfoData.php
│   └── Order/
│       └── CreateOrderData.php
│
├── Enums/                      # Перечисления
│   ├── OrderStatus.php
│   ├── PaymentType.php
│   ├── ShippingMethod.php
│   ├── PromocodeType.php
│   ├── LinkType.php
│   ├── Gender.php
│   └── UnitType.php
│
├── Models/                     # Eloquent модели
│   ├── User.php
│   ├── OtpCode.php
│   ├── Address.php
│   ├── Store.php
│   ├── StoreSchedule.php
│   ├── Category.php
│   ├── Product.php
│   ├── ProductImage.php
│   ├── ProductAttribute.php
│   ├── Cart.php
│   ├── CartItem.php
│   ├── CartPromocode.php
│   ├── Promocode.php
│   ├── Order.php
│   ├── OrderAddress.php
│   ├── OrderItem.php
│   ├── OrderPromocode.php
│   ├── Favorite.php
│   ├── Story.php
│   ├── StoryImage.php
│   ├── Promotion.php
│   ├── ProductGroup.php
│   ├── ProductGroupItem.php
│   ├── Banner.php
│   ├── StoreReview.php
│   └── ProductReview.php
│
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   │           ├── AuthController.php
│   │           ├── AddressController.php
│   │           ├── StoreController.php
│   │           ├── CategoryController.php
│   │           ├── ProductController.php
│   │           ├── CartController.php
│   │           ├── OrderController.php
│   │           ├── DeliveryController.php
│   │           ├── FavoriteController.php
│   │           ├── StoryController.php
│   │           ├── PromotionController.php
│   │           ├── ProductGroupController.php
│   │           ├── BannerController.php
│   │           ├── StoreReviewController.php
│   │           ├── ProductReviewController.php
│   │           ├── HomeController.php
│   │           ├── StorePageController.php
│   │           └── StoreCatalogController.php
│   │
│   ├── Requests/
│   │   └── Api/
│   │       └── V1/
│   │           ├── Auth/
│   │           │   ├── PhoneLoginRequest.php
│   │           │   ├── PhoneConfirmRequest.php
│   │           │   └── UpdateProfileRequest.php
│   │           ├── Address/
│   │           │   ├── CreateAddressRequest.php
│   │           │   └── UpdateAddressRequest.php
│   │           ├── Cart/
│   │           │   ├── AddToCartRequest.php
│   │           │   ├── UpdateCartItemRequest.php
│   │           │   └── ApplyPromocodeRequest.php
│   │           └── Order/
│   │               ├── CreateOrderRequest.php
│   │               └── AppendOrderRequest.php
│   │
│   └── Resources/
│       └── Api/
│           └── V1/
│               ├── UserResource.php
│               ├── AddressResource.php
│               ├── StoreResource.php
│               ├── WorkingHoursResource.php
│               ├── CategoryResource.php
│               ├── ProductResource.php
│               ├── ProductImageResource.php
│               ├── ProductAttributeResource.php
│               ├── CartResource.php
│               ├── CartStoreGroupResource.php
│               ├── CartItemResource.php
│               ├── OrderResource.php
│               ├── OrderItemResource.php
│               ├── OrderAddressResource.php
│               ├── PromocodeResource.php
│               ├── DeliverySlotResource.php
│               ├── DeliveryInfoResource.php
│               ├── StoryResource.php
│               ├── PromotionResource.php
│               ├── ProductGroupResource.php
│               ├── BannerResource.php
│               └── ReviewResource.php
│
├── Policies/                   # Авторизация
│   ├── AddressPolicy.php
│   ├── OrderPolicy.php
│   ├── CartItemPolicy.php
│   └── ReviewPolicy.php
│
├── Events/                     # События
│   ├── Order/
│   │   ├── OrderCreated.php
│   │   ├── OrderCancelled.php
│   │   └── OrderPaid.php
│   └── Customer/
│       ├── CustomerCreated.php
│       ├── CustomerUpdated.php
│       └── AddressCreated.php
│
├── Listeners/                  # Обработчики событий
│   └── RabbitMQ/
│       ├── SendOrderCreatedEvent.php
│       └── SendCustomerCreatedEvent.php
│
└── Exceptions/                 # Кастомные исключения
    ├── InsufficientStockException.php
    ├── InvalidPromocodeException.php
    ├── MinOrderAmountException.php
    ├── DeliverySystemException.php
    └── OrderCannotBeCancelledException.php
```

---

## 3. Слои приложения

```
┌─────────────────────────────────────────────────────────────┐
│                      HTTP Layer                              │
│  Controllers → Form Requests → Resources                     │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                         │
│  Actions → DTOs → Events                                     │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                     Domain Layer                             │
│  Models → Enums → Policies → Exceptions                      │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                  Infrastructure Layer                        │
│  Services (HTTP Clients, SMS, RabbitMQ)                      │
└─────────────────────────────────────────────────────────────┘
```

**Правила зависимостей:**
- Controllers зависят от Actions, Requests, Resources
- Actions зависят от Models, Services, DTOs, Events
- Services зависят только от внешних API
- Models не зависят ни от чего (кроме других Models)

---

## 4. Actions

**Action** — класс, выполняющий одну бизнес-операцию. Содержит метод `execute()`.

### Когда создавать Action

- Операция изменяет данные (create, update, delete)
- Операция содержит бизнес-логику
- Операция может быть вызвана из разных мест (контроллер, команда, job)

### Когда НЕ нужен Action

- Простое чтение данных (используй запрос в контроллере)
- Операция — просто CRUD без логики

### Структура Action

```php
<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\DTOs\Order\CreateOrderData;
use App\Enums\OrderStatus;
use App\Events\Order\OrderCreated;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\MinOrderAmountException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryService;
use Illuminate\Support\Facades\DB;

final class CreateOrderAction
{
    public function __construct(
        private ReserveProductsAction $reserveProducts,
        private DeliveryService $deliveryService,
    ) {}

    /**
     * Создаёт заказ из корзины.
     *
     * @throws InsufficientStockException
     * @throws MinOrderAmountException
     */
    public function execute(CreateOrderData $data): int
    {
        return DB::transaction(function () use ($data) {
            // 1. Получить корзину и группу магазина
            $cart = Cart::where('user_id', $data->user->id)->firstOrFail();
            $storeGroup = $this->getStoreGroup($cart, $data->storeId);

            // 2. Проверить минимальную сумму
            $this->validateMinOrderAmount($storeGroup);

            // 3. Резервировать товары
            $this->reserveProducts->execute($storeGroup->items);

            // 4. Получить слот доставки
            $slot = $this->deliveryService->resolveSlot($data->deliverySlotId);

            // 5. Создать заказ
            $order = $this->createOrder($data, $storeGroup, $slot);

            // 6. Создать snapshot адреса
            $this->createOrderAddress($order, $data->address);

            // 7. Создать snapshot товаров
            $this->createOrderItems($order, $storeGroup->items);

            // 8. Создать snapshot промокода (если есть)
            if ($storeGroup->promocode) {
                $this->createOrderPromocode($order, $storeGroup);
            }

            // 9. Очистить корзину для этого магазина
            $this->clearCartForStore($cart, $data->storeId);

            // 10. Отправить событие
            event(new OrderCreated($order));

            return $order->id;
        });
    }

    // ... private методы
}
```

### Правила для Actions

1. **Один публичный метод** — `execute()` (или `handle()`)
2. **Типизация параметров** — используй DTOs для сложных входных данных
3. **Типизация возврата** — всегда указывай return type
4. **Исключения** — бросай typed exceptions, не возвращай `false`
5. **Транзакции** — оборачивай в `DB::transaction()` если несколько записей
6. **События** — диспатчь в конце успешной операции
7. **Final class** — Actions не наследуются

### Именование Actions

```
{Глагол}{Сущность}Action.php

Примеры:
- CreateOrderAction.php
- CancelOrderAction.php
- ApplyPromocodeAction.php
- ToggleFavoriteAction.php
- ReserveProductsAction.php
- SendOtpAction.php
- VerifyOtpAction.php
```

---

## 5. Services

**Service** — класс для внешних интеграций или переиспользуемой stateless-логики.

### Типы Services

#### 1. HTTP Clients (внешние API)

```php
<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\Exceptions\DeliverySystemException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class DeliverySystemClient
{
    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl(config('services.delivery_system.url'))
            ->withToken(config('services.delivery_system.api_key'))
            ->timeout(config('services.delivery_system.timeout', 10));
    }

    /**
     * Получить слоты доставки.
     *
     * @throws DeliverySystemException
     */
    public function getSlots(float $latitude, float $longitude, string $date): DeliveryInfoData
    {
        $response = $this->http->post('/api/slots', [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'date' => $date,
        ]);

        if ($response->failed()) {
            throw new DeliverySystemException(
                'Failed to fetch delivery slots: ' . $response->body()
            );
        }

        return DeliveryInfoData::fromApiResponse($response->json());
    }
}
```

#### 2. Domain Services (переиспользуемая логика)

```php
<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\DTOs\Cart\CartStoreGroupData;
use App\Models\Promocode;
use App\Models\Product;

final class CartCalculator
{
    /**
     * Рассчитать скидку по промокоду.
     */
    public function calculateDiscount(CartStoreGroupData $group): int
    {
        if (!$group->promocode) {
            return 0;
        }

        // Только товары без скидки
        $eligibleSubtotal = $group->items
            ->filter(fn ($item) => !$this->hasDiscount($item->product))
            ->sum(fn ($item) => $item->price * $item->quantity);

        if ($eligibleSubtotal === 0) {
            return 0;
        }

        return match ($group->promocode->type) {
            PromocodeType::Percent => (int) round($eligibleSubtotal * $group->promocode->value / 100),
            PromocodeType::Fixed => min($group->promocode->value, $eligibleSubtotal),
        };
    }

    /**
     * Рассчитать стоимость доставки.
     */
    public function calculateDeliveryCost(CartStoreGroupData $group): int
    {
        $subtotal = $group->items->sum(fn ($item) => $item->price * $item->quantity);
        $store = $group->store;

        if ($store->free_delivery_from && $subtotal >= $store->free_delivery_from) {
            return 0;
        }

        return $store->delivery_price ?? 0;
    }

    private function hasDiscount(Product $product): bool
    {
        if ($product->price_old !== null) {
            return true;
        }

        return $product->promotions()->active()->exists();
    }
}
```

#### 3. Wrapper Services (с кешированием, fallback)

```php
<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\DTOs\Delivery\DeliverySlotData;
use App\Exceptions\DeliverySystemException;
use App\Models\Address;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class DeliveryService
{
    public function __construct(
        private DeliverySystemClient $client,
    ) {}

    /**
     * Получить информацию о доставке с кешированием.
     */
    public function getDeliveryInfo(Address $address, string $date): DeliveryInfoData
    {
        $cacheKey = sprintf(
            'delivery_slots:%s:%s:%s',
            $address->latitude,
            $address->longitude,
            $date
        );

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($address, $date) {
            try {
                return $this->client->getSlots(
                    $address->latitude,
                    $address->longitude,
                    $date
                );
            } catch (DeliverySystemException $e) {
                // Fallback: попробовать из просроченного кеша
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    return $cached;
                }
                throw $e;
            }
        });
    }

    /**
     * Распарсить ID слота в данные слота.
     */
    public function resolveSlot(int $slotId): DeliverySlotData
    {
        $start = Carbon::createFromTimestamp($slotId);
        $end = $start->copy()->addHours(2);

        return new DeliverySlotData(
            id: $slotId,
            timeSlot: $start->format('H:i') . '-' . $end->format('H:i'),
            startAt: $start,
            endAt: $end,
        );
    }
}
```

### Правила для Services

1. **Stateless** — не храни состояние между вызовами
2. **Инъекция через конструктор** — все зависимости явно
3. **Final class** — Services не наследуются
4. **Один домен** — один Service не должен знать о нескольких доменах

---

## 6. DTOs

**DTO (Data Transfer Object)** — неизменяемый объект для передачи данных между слоями.

### Когда использовать DTO

- Передача нескольких связанных параметров в Action
- Структурированный ответ от внешнего API
- Сложные вычисляемые данные (CartStoreGroup)

### Структура DTO

```php
<?php

declare(strict_types=1);

namespace App\DTOs\Order;

use App\Models\Address;
use App\Models\User;

final readonly class CreateOrderData
{
    public function __construct(
        public User $user,
        public int $storeId,
        public Address $address,
        public int $deliverySlotId,
        public ?string $comment = null,
    ) {}

    /**
     * Создать из Request.
     */
    public static function fromRequest(CreateOrderRequest $request): self
    {
        return new self(
            user: $request->user(),
            storeId: $request->validated('storeId'),
            address: Address::findOrFail($request->validated('addressId')),
            deliverySlotId: $request->validated('deliverySlotId'),
            comment: $request->validated('comment'),
        );
    }
}
```

### DTO для API Response

```php
<?php

declare(strict_types=1);

namespace App\DTOs\Delivery;

use Carbon\Carbon;

final readonly class DeliverySlotData
{
    public function __construct(
        public int $id,
        public string $timeSlot,
        public Carbon $startAt,
        public Carbon $endAt,
    ) {}

    /**
     * Создать из ответа API.
     */
    public static function fromApiResponse(array $data): self
    {
        $start = Carbon::parse($data['start']);
        $end = Carbon::parse($data['end']);

        return new self(
            id: $start->timestamp,
            timeSlot: $start->format('H:i') . '-' . $end->format('H:i'),
            startAt: $start,
            endAt: $end,
        );
    }
}
```

### Правила для DTOs

1. **readonly class** — PHP 8.2+, все свойства неизменяемы
2. **Типизация** — все свойства с типами
3. **Named constructors** — `fromRequest()`, `fromApiResponse()`, `fromModel()`
4. **Без логики** — только данные, никаких вычислений
5. **Final** — DTOs не наследуются

---

## 7. Enums

Все перечисления — PHP 8.1 native enums со строковыми значениями.

### Пример Enum

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Assembling = 'assembling';
    case Assembled = 'assembled';
    case InDelivery = 'inDelivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Статусы, в которых можно отменить заказ.
     */
    public static function cancellable(): array
    {
        return [
            self::Pending,
            self::Assembling,
        ];
    }

    /**
     * Можно ли отменить заказ в этом статусе.
     */
    public function canCancel(): bool
    {
        return in_array($this, self::cancellable(), true);
    }

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает обработки',
            self::Assembling => 'Собирается',
            self::Assembled => 'Собран',
            self::InDelivery => 'В доставке',
            self::Delivered => 'Доставлен',
            self::Cancelled => 'Отменён',
        };
    }
}
```

### Использование в Model

```php
// В модели Order
protected function casts(): array
{
    return [
        'status' => OrderStatus::class,
        'payment_type' => PaymentType::class,
        'shipping_method' => ShippingMethod::class,
    ];
}

// Использование
$order->status === OrderStatus::Pending;
$order->status->canCancel();
$order->status->label();
```

### Список Enums проекта

| Enum | Значения | Использование |
|------|----------|---------------|
| `OrderStatus` | pending, assembling, assembled, inDelivery, delivered, cancelled | orders.status |
| `PaymentType` | cash, card | orders.payment_type |
| `ShippingMethod` | courier, pickup | orders.shipping_method |
| `PromocodeType` | percent, fixed | promocodes.type |
| `Gender` | male, female | users.gender |
| `UnitType` | piece, kg | products.unit_type |
| `LinkType` | none, product, store, category, external | stories, banners |

---

## 8. Models

### Структура модели

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'store_id',
        'source',
        'status',
        'payment_type',
        'is_paid',
        'subtotal',
        'discount',
        'delivery_cost',
        'total',
        'items_count',
        'delivery_slot_start',
        'delivery_slot_end',
        'delivery_slot_display',
        'shipping_method',
        'tracking_number',
        'comment',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_type' => PaymentType::class,
            'shipping_method' => ShippingMethod::class,
            'is_paid' => 'boolean',
            'delivery_slot_start' => 'datetime',
            'delivery_slot_end' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function promocode(): HasOne
    {
        return $this->hasOne(OrderPromocode::class);
    }

    // ==================== Scopes ====================

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::Pending);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', OrderStatus::Delivered);
    }

    // ==================== Accessors ====================

    public function getCanCancelAttribute(): bool
    {
        return $this->status->canCancel();
    }
}
```

### Правила для Models

1. **Final class** — модели не наследуются (кроме базового Model)
2. **Секции с комментариями** — Casts, Relations, Scopes, Accessors
3. **Typed relations** — всегда указывай return type
4. **Fillable** — явно перечисляй поля (не guarded)
5. **Casts через метод** — `protected function casts(): array`
6. **Никакой бизнес-логики** — только data access

### Accessors vs Methods

```php
// Accessor — для простых вычислений, используется как свойство
public function getFullNameAttribute(): string
{
    return trim("{$this->first_name} {$this->last_name}");
}
// $user->full_name

// Method — для сложных вычислений или с параметрами
public function isFavorite(?User $user): bool
{
    if (!$user) {
        return false;
    }
    return $this->favorites()->where('user_id', $user->id)->exists();
}
// $product->isFavorite($user)
```

---

## 9. Controllers

### Структура контроллера

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\CreateOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    /**
     * GET /api/v1/orders
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->forUser($request->user())
            ->with(['store', 'items', 'address', 'promocode'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('perPage', 20));

        return OrderResource::collection($orders);
    }

    /**
     * POST /api/v1/orders
     */
    public function store(
        CreateOrderRequest $request,
        CreateOrderAction $action,
    ): JsonResponse {
        $orderId = $action->execute(
            CreateOrderData::fromRequest($request)
        );

        return response()->json(['orderId' => $orderId], 201);
    }

    /**
     * GET /api/v1/orders/{order}
     */
    public function show(Request $request, Order $order): OrderResource
    {
        $this->authorize('view', $order);

        $order->load(['store', 'items', 'address', 'promocode']);

        return new OrderResource($order);
    }

    /**
     * POST /api/v1/orders/{order}/cancel
     */
    public function cancel(
        Request $request,
        Order $order,
        CancelOrderAction $action,
    ): OrderResource {
        $this->authorize('cancel', $order);

        $action->execute($order);

        return new OrderResource($order->fresh());
    }
}
```

### Правила для Controllers

1. **Тонкие контроллеры** — только валидация, авторизация, вызов Action
2. **Final class** — контроллеры не наследуются
3. **Type hints** — для всех параметров и возвращаемых значений
4. **Один resource** — контроллер работает с одним ресурсом
5. **Стандартные методы** — index, store, show, update, destroy
6. **Кастомные actions** — отдельные методы (cancel, approve, etc.)
7. **PHPDoc** — комментарий с HTTP методом и путём

### Авторизация в контроллере

```php
// Через Policy
$this->authorize('view', $order);
$this->authorize('cancel', $order);

// Через middleware в routes
Route::get('orders/{order}', [OrderController::class, 'show'])
    ->can('view', 'order');
```

---

## 10. Form Requests

### Структура Request

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Авторизация через middleware
    }

    public function rules(): array
    {
        return [
            'storeId' => ['required', 'integer', Rule::exists('stores', 'id')],
            'addressId' => [
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', $this->user()->id),
            ],
            'deliverySlotId' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'storeId.required' => 'Укажите магазин',
            'storeId.exists' => 'Магазин не найден',
            'addressId.required' => 'Укажите адрес доставки',
            'addressId.exists' => 'Адрес не найден',
            'deliverySlotId.required' => 'Выберите время доставки',
            'comment.max' => 'Комментарий слишком длинный',
        ];
    }

    public function attributes(): array
    {
        return [
            'storeId' => 'магазин',
            'addressId' => 'адрес',
            'deliverySlotId' => 'слот доставки',
            'comment' => 'комментарий',
        ];
    }
}
```

### Правила для Requests

1. **Final class** — requests не наследуются
2. **Кастомные сообщения** — всегда на русском
3. **Проверка владельца** — в rules через `where`
4. **Attributes** — для автоматических сообщений

---

## 11. API Resources

### Структура Resource

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Order
 */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store' => new StoreResource($this->whenLoaded('store')),
            'status' => $this->status->value,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'address' => new OrderAddressResource($this->whenLoaded('address')),
            'promocode' => new PromocodeResource($this->whenLoaded('promocode')),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'deliveryCost' => $this->delivery_cost,
            'total' => $this->total,
            'itemsCount' => $this->items_count,
            'deliverySlotTimeSlot' => $this->delivery_slot_display,
            'isPaid' => $this->is_paid,
            'paymentType' => $this->payment_type->value,
            'comment' => $this->comment,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
```

### Правила для Resources

1. **camelCase** — все ключи в camelCase
2. **@mixin** — для IDE autocompletion
3. **whenLoaded** — для optional relations
4. **ISO 8601** — для дат: `->toIso8601String()`
5. **Enum values** — `$this->status->value`
6. **Final class** — resources не наследуются

### Пагинация

```php
// В контроллере
return ProductResource::collection($products);

// Автоматически обернётся в:
{
    "data": [...],
    "links": {...},
    "meta": {...}
}
```

Для кастомной обёртки:

```php
public function index(Request $request)
{
    $products = Product::paginate($request->integer('perPage', 20));

    return [
        'hasMore' => $products->hasMorePages(),
        'data' => ProductResource::collection($products),
        'total' => $products->total(),
    ];
}
```

---

## 12. Policies

### Структура Policy

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    /**
     * Просмотр заказа.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }

    /**
     * Отмена заказа.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $user->id === $order->user_id && $order->status->canCancel();
    }

    /**
     * Дополнение заказа.
     */
    public function append(User $user, Order $order): bool
    {
        return $user->id === $order->user_id && $order->status === OrderStatus::Pending;
    }
}
```

### Регистрация Policy

```php
// bootstrap/providers.php или AuthServiceProvider

use App\Models\Order;
use App\Policies\OrderPolicy;

// Laravel 12 автоматически находит policies по конвенции:
// App\Policies\{Model}Policy

// Если нужно явно:
Gate::policy(Order::class, OrderPolicy::class);
```

---

## 13. Events & Listeners

### Структура Event

```php
<?php

declare(strict_types=1);

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Order $order,
    ) {}
}
```

### Структура Listener

```php
<?php

declare(strict_types=1);

namespace App\Listeners\RabbitMQ;

use App\Events\Order\OrderCreated;
use App\Services\RabbitMQ\RabbitMQPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendOrderCreatedEvent implements ShouldQueue
{
    public function __construct(
        private RabbitMQPublisher $publisher,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $this->publisher->publish(
            exchange: 'order',
            routingKey: 'order.created',
            data: $this->buildPayload($event->order),
        );
    }

    private function buildPayload(Order $order): array
    {
        // ...
    }
}
```

### Регистрация

```php
// app/Providers/EventServiceProvider.php или bootstrap/app.php

Event::listen(OrderCreated::class, SendOrderCreatedEvent::class);
```

### Когда использовать Events

- Уведомления внешних систем (RabbitMQ, webhooks)
- Отложенные операции (email, push)
- Логирование аудита
- **НЕ** для бизнес-логики (используй Actions)

---

## 14. Exceptions

### Структура Exception

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

final class InsufficientStockException extends Exception
{
    public function __construct(
        public readonly string $productTitle,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Товар '{$productTitle}' недоступен в нужном количестве. " .
            "Доступно: {$available}, запрошено: {$requested}"
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'quantity' => [$this->getMessage()],
            ],
        ], 422);
    }
}
```

### Список Exceptions

| Exception | HTTP Code | Когда |
|-----------|-----------|-------|
| `InsufficientStockException` | 422 | Недостаточно товара |
| `InvalidPromocodeException` | 422 | Промокод недействителен |
| `MinOrderAmountException` | 422 | Сумма ниже минимальной |
| `OrderCannotBeCancelledException` | 422 | Заказ нельзя отменить |
| `DeliverySystemException` | 503 | DeliverySystem недоступна |

---

## 15. Тестирование

### Структура тестов

```
tests/
├── Feature/
│   └── Api/
│       └── V1/
│           ├── Auth/
│           │   └── AuthTest.php
│           ├── Address/
│           │   └── AddressTest.php
│           ├── Cart/
│           │   └── CartTest.php
│           └── Order/
│               └── OrderTest.php
└── Unit/
    ├── Actions/
    │   └── Order/
    │       └── CreateOrderActionTest.php
    ├── Services/
    │   └── Cart/
    │       └── CartCalculatorTest.php
    └── DTOs/
        └── Delivery/
            └── DeliverySlotDataTest.php
```

### Feature Test (API)

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_success(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create(['min_order_amount' => 500]);
        $product = Product::factory()->for($store)->create(['price' => 300, 'quantity' => 10]);
        $address = Address::factory()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 2,
            'price' => 300,
        ]);

        // Act
        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => now()->addDay()->timestamp,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonStructure(['orderId']);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'status' => OrderStatus::Pending->value,
            'total' => 600,
        ]);

        // Товар зарезервирован
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'quantity' => 8,
        ]);

        // Корзина очищена
        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_create_order_fails_with_empty_cart(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create();
        Cart::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => now()->addDay()->timestamp,
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_order_fails_with_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['quantity' => 1]);
        $address = Address::factory()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 5, // Больше чем на складе
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => now()->addDay()->timestamp,
        ]);

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Товар недоступен в нужном количестве']);
    }
}
```

### Unit Test (Action)

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cart;

use App\DTOs\Cart\CartStoreGroupData;
use App\Enums\PromocodeType;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Services\Cart\CartCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private CartCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CartCalculator();
    }

    public function test_calculate_percent_discount(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['price' => 100]);
        $promocode = Promocode::factory()->create([
            'type' => PromocodeType::Percent,
            'value' => 10,
        ]);

        $group = new CartStoreGroupData(
            store: $store,
            items: collect([
                (object) ['product' => $product, 'price' => 100, 'quantity' => 2],
            ]),
            promocode: $promocode,
        );

        $discount = $this->calculator->calculateDiscount($group);

        $this->assertEquals(20, $discount); // 10% от 200
    }

    public function test_discount_not_applied_to_sale_items(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'price' => 80,
            'price_old' => 100, // Уже со скидкой
        ]);
        $promocode = Promocode::factory()->create([
            'type' => PromocodeType::Percent,
            'value' => 10,
        ]);

        $group = new CartStoreGroupData(
            store: $store,
            items: collect([
                (object) ['product' => $product, 'price' => 80, 'quantity' => 1],
            ]),
            promocode: $promocode,
        );

        $discount = $this->calculator->calculateDiscount($group);

        $this->assertEquals(0, $discount); // Промокод не применяется
    }
}
```

### Правила тестирования

1. **Arrange-Act-Assert** — три секции в каждом тесте
2. **One assertion per concept** — но можно несколько assert на одно действие
3. **Factories** — для создания моделей
4. **RefreshDatabase** — чистая БД для каждого теста
5. **Naming** — `test_{что_тестируем}_{ожидаемый_результат}`

---

## 16. Naming Conventions

### Файлы и классы

| Тип | Формат | Пример |
|-----|--------|--------|
| Model | `{Singular}.php` | `Order.php`, `CartItem.php` |
| Controller | `{Singular}Controller.php` | `OrderController.php` |
| Action | `{Verb}{Noun}Action.php` | `CreateOrderAction.php` |
| Service | `{Noun}Service.php` | `DeliveryService.php` |
| DTO | `{Noun}Data.php` | `CreateOrderData.php` |
| Request | `{Verb}{Noun}Request.php` | `CreateOrderRequest.php` |
| Resource | `{Singular}Resource.php` | `OrderResource.php` |
| Policy | `{Singular}Policy.php` | `OrderPolicy.php` |
| Event | `{Noun}{PastVerb}.php` | `OrderCreated.php` |
| Exception | `{Noun}Exception.php` | `InsufficientStockException.php` |
| Enum | `{Singular}.php` | `OrderStatus.php` |
| Test | `{Subject}Test.php` | `OrderTest.php` |

### Методы

| Тип | Формат | Пример |
|-----|--------|--------|
| Action::execute | `execute(...)` | `execute(CreateOrderData $data)` |
| Controller CRUD | index, store, show, update, destroy | — |
| Controller custom | `{verb}` | `cancel()`, `approve()` |
| Scope | `scope{Name}` | `scopeActive($query)` |
| Accessor | `get{Name}Attribute` | `getFullNameAttribute()` |
| Relation | `{camelCase}` | `orderItems()`, `store()` |

### База данных

| Тип | Формат | Пример |
|-----|--------|--------|
| Таблица | `{snake_case_plural}` | `order_items`, `cart_promocodes` |
| Колонка | `{snake_case}` | `user_id`, `is_active`, `created_at` |
| FK | `{singular}_id` | `user_id`, `store_id` |
| Pivot | `{singular}_{singular}` (алфавит) | `order_promocode`, `product_promotion` |
| Boolean | `is_{adjective}` или `has_{noun}` | `is_active`, `is_paid`, `has_children` |

### API Response (JSON)

| Тип | Формат | Пример |
|-----|--------|--------|
| Ключи | `camelCase` | `firstName`, `deliveryCost`, `isActive` |
| Boolean | `is{Adjective}` | `isActive`, `isPaid`, `isFavorite` |
| Counts | `{noun}Count` | `itemsCount`, `reviewsCount` |
| Dates | ISO 8601 | `2024-01-15T10:30:00.000Z` |
| Enums | string value | `"pending"`, `"cash"` |
| Money | int (рубли) или decimal | `500`, `99.99` |

---

## 17. Чеклист при создании нового модуля

### 1. Модели и миграции

- [ ] Создать миграцию: `php artisan make:migration create_{table}_table`
- [ ] Создать модель: `php artisan make:model {Name} -f`
- [ ] Добавить `fillable`, `casts`, relations, scopes
- [ ] Настроить factory

### 2. Enums (если нужны)

- [ ] Создать Enum в `app/Enums/`
- [ ] Добавить в casts модели

### 3. DTOs (если нужны)

- [ ] Создать DTO в `app/DTOs/{Domain}/`
- [ ] Добавить static constructors

### 4. Actions

- [ ] Создать Action в `app/Actions/{Domain}/`
- [ ] Добавить типизацию, исключения, события

### 5. Services (если нужны)

- [ ] Создать Service в `app/Services/{Domain}/`
- [ ] Добавить в `config/services.php` если внешний API

### 6. HTTP слой

- [ ] Создать Controller: `php artisan make:controller Api/V1/{Name}Controller`
- [ ] Создать Form Requests в `app/Http/Requests/Api/V1/{Domain}/`
- [ ] Создать Resource в `app/Http/Resources/Api/V1/`

### 7. Policy (если нужна авторизация)

- [ ] Создать Policy: `php artisan make:policy {Name}Policy --model={Name}`
- [ ] Добавить методы авторизации

### 8. Routes

- [ ] Добавить routes в `routes/api.php`
- [ ] Добавить middleware (auth:sanctum, can, throttle)

### 9. Events & Listeners (если нужны)

- [ ] Создать Event в `app/Events/{Domain}/`
- [ ] Создать Listener в `app/Listeners/`
- [ ] Зарегистрировать связь

### 10. Exceptions

- [ ] Создать Exception в `app/Exceptions/`
- [ ] Добавить `render()` метод

### 11. Тесты

- [ ] Feature тесты API в `tests/Feature/Api/V1/{Domain}/`
- [ ] Unit тесты Actions/Services в `tests/Unit/`
- [ ] Запустить: `php artisan test --filter={Name}`

### 12. Финализация

- [ ] Запустить Pint: `vendor/bin/pint`
- [ ] Запустить все тесты: `php artisan test`
- [ ] Проверить N+1 запросы (Telescope/Debugbar)

---

## Приложение A: Создание модуля на примере Orders

```bash
# 1. Миграции
php artisan make:migration create_orders_table
php artisan make:migration create_order_addresses_table
php artisan make:migration create_order_items_table
php artisan make:migration create_order_promocodes_table

# 2. Модели с factories
php artisan make:model Order -f
php artisan make:model OrderAddress -f
php artisan make:model OrderItem -f
php artisan make:model OrderPromocode -f

# 3. Controller
php artisan make:controller Api/V1/OrderController

# 4. Requests
php artisan make:request Api/V1/Order/CreateOrderRequest
php artisan make:request Api/V1/Order/AppendOrderRequest

# 5. Resources
php artisan make:resource Api/V1/OrderResource
php artisan make:resource Api/V1/OrderItemResource
php artisan make:resource Api/V1/OrderAddressResource

# 6. Policy
php artisan make:policy OrderPolicy --model=Order

# 7. Tests
php artisan make:test Api/V1/Order/OrderTest
php artisan make:test Actions/Order/CreateOrderActionTest --unit
```

Затем вручную создать:
- `app/Enums/OrderStatus.php`
- `app/DTOs/Order/CreateOrderData.php`
- `app/Actions/Order/CreateOrderAction.php`
- `app/Actions/Order/CancelOrderAction.php`
- `app/Actions/Order/ReserveProductsAction.php`
- `app/Events/Order/OrderCreated.php`
- `app/Exceptions/InsufficientStockException.php`

---

## Приложение B: Полезные команды

```bash
# Разработка
composer run dev              # Запуск dev-сервера

# Тесты
php artisan test --compact                           # Все тесты
php artisan test --compact --filter=OrderTest        # Один файл
php artisan test --compact --filter=test_create_order # Один тест

# Форматирование
vendor/bin/pint --dirty       # Только изменённые файлы
vendor/bin/pint               # Все файлы

# Миграции
php artisan migrate           # Применить
php artisan migrate:fresh     # Пересоздать БД
php artisan migrate:fresh --seed # С сидами

# Artisan
php artisan make:model Name -mf   # Модель + миграция + factory
php artisan make:controller Api/V1/NameController
php artisan make:request Api/V1/Name/CreateNameRequest
php artisan make:resource Api/V1/NameResource
php artisan make:policy NamePolicy --model=Name
php artisan make:test Api/V1/Name/NameTest

# Очистка
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

---

*Документ создан: 2025-01-28*
*Последнее обновление: 2025-01-28*
