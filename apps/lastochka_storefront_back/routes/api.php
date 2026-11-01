<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AppSettingsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BonusController;
use App\Http\Controllers\Api\V1\LoyaltyCardController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ComparisonController;
use App\Http\Controllers\Api\V1\ClientOrderController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\Delivery\AddressPickupController;
use App\Http\Controllers\Api\V1\Delivery\DeliveryCalculateController;
use App\Http\Controllers\Api\V1\Delivery\DeliveryOptionsController;
use App\Http\Controllers\Api\V1\Delivery\DeliveryTariffsController;
use App\Http\Controllers\Api\V1\Delivery\PickupController;
use App\Http\Controllers\Api\V1\EstimateController;
use App\Http\Controllers\Api\V1\FaqController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\FcmTokenController;
use App\Http\Controllers\Api\V1\ProductGroupFavoriteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PreorderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductIssueReportController;
use App\Http\Controllers\Api\V1\ProductGroupController;
use App\Http\Controllers\Api\V1\ProductReviewController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StoreReviewController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\StoryController;
use App\Http\Controllers\Api\V1\VacancyController;
use App\Http\Controllers\Debug\RabbitDebugController;
use App\Http\Middleware\RabbitDebugMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// Лимиты запросов: у каждого throttle обязателен свой префикс третьим параметром
// ('throttle:5,1,orders.store:'). Без него ключ счётчика = только пользователь (или IP),
// и все роуты с throttle делят один счётчик: пять запросов карты лояльности за минуту
// исчерпывали лимит создания заказа (429 Too Many Attempts в мобилке).

// ==================== Webhooks (без auth) ====================
Route::post('v1/webhooks/yookassa', [\App\Http\Controllers\Api\V1\WebhookController::class, 'yookassa']);
Route::post('v1/webhooks/atol', [\App\Http\Controllers\Api\V1\WebhookController::class, 'atol']);
Route::post('v1/webhooks/yandex-delivery', [\App\Http\Controllers\Api\V1\YandexWebhookController::class, 'handle'])->name('webhooks.yandex-delivery');

// ==================== Internal (без auth) ====================
Route::post('v1/internal/yandex-claims/{claimId}/cancel', [\App\Http\Controllers\Api\V1\YandexWebhookController::class, 'cancelFromAdmin']);
Route::post('v1/internal/orders/{order}/settle-payment', [\App\Http\Controllers\Api\V1\InternalOrderPaymentController::class, 'settle'])
    ->middleware('internal.token');

// ==================== Аналитика: приём B2C-событий (анонимно или с токеном) ====================
Route::post('v1/analytics/events', [\App\Http\Controllers\Api\V1\AnalyticsEventController::class, 'store'])
    ->middleware([\App\Http\Middleware\OptionalAuth::class, 'throttle:120,1,analytics-events:']);

Route::prefix('v1')->group(function () {
    // ==================== Auth ====================
    Route::prefix('auth')->group(function () {
        Route::post('phone-login', [AuthController::class, 'phoneLogin']);
        Route::post('phone-confirm', [AuthController::class, 'phoneConfirm']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('profile', [AuthController::class, 'getProfile']);
            Route::put('profile', [AuthController::class, 'updateProfile']);
            Route::post('adult-confirm', [AuthController::class, 'confirmAdult']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('change-phone', [AuthController::class, 'changePhone']);
            Route::post('change-phone/confirm', [AuthController::class, 'changePhoneConfirm']);
            Route::delete('account', [AuthController::class, 'deleteAccount']);
        });
    });

    // ==================== FCM Tokens ====================
    Route::prefix('fcm-tokens')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [FcmTokenController::class, 'index']);
        Route::post('/', [FcmTokenController::class, 'store']);
        Route::delete('/', [FcmTokenController::class, 'destroy']);
    });

    // ==================== Stories ====================
    Route::prefix('stories')->group(function () {
        Route::get('/', [StoryController::class, 'index']);
        Route::get('/{story}', [StoryController::class, 'show']);
    });

    // ==================== Brands ====================
    Route::get('brands', [BrandController::class, 'index']);

    // ==================== FAQ ====================
    Route::get('faq', [FaqController::class, 'index']);

    // ==================== Обратная связь ====================
    // Защита от автоматических отправок: не более 5 обращений в час с одного IP.
    Route::post('feedback', [\App\Http\Controllers\Api\V1\FeedbackController::class, 'store'])
        ->middleware('throttle:5,60,feedback:');

    // ==================== Работа в компании ====================
    Route::prefix('vacancies')->group(function () {
        Route::get('/', [VacancyController::class, 'index']);
        Route::get('/{vacancy}', [VacancyController::class, 'show'])->where('vacancy', '[0-9]+');
        // Защита от автоматических отправок: не более 5 откликов в час с одного IP.
        Route::post('/{vacancy}/responses', [VacancyController::class, 'respond'])
            ->where('vacancy', '[0-9]+')
            ->middleware('throttle:5,60,vacancy-responses:');
    });

    // ==================== Статичные страницы ====================
    Route::prefix('pages')->group(function () {
        Route::get('/', [PageController::class, 'index']);
        Route::get('/{slug}', [PageController::class, 'show'])->where('slug', '[a-z0-9-]+');
    });

    // ==================== Cities ====================
    Route::get('cities', [\App\Http\Controllers\Api\V1\CityController::class, 'index']);

    // ==================== Stores ====================
    Route::prefix('stores')->group(function () {
        Route::get('/', [StoreController::class, 'index']);
        Route::get('/nearest', [StoreController::class, 'nearest']);
        Route::get('/{storeId}', [StoreController::class, 'show'])->where('storeId', '[0-9]+');
        Route::get('/{storeId}/bonus-settings', [StoreController::class, 'bonusSettings'])->where('storeId', '[0-9]+');
        Route::get('/{storeId}/menu-sections', [\App\Http\Controllers\Api\V1\MenuSectionController::class, 'index'])->where('storeId', '[0-9]+');
    });

    // ==================== Pickup Slots ====================
    Route::get('stores/{store}/pickup-slots', \App\Http\Controllers\Api\V1\PickupSlotController::class)
        ->middleware('auth:sanctum');

    // ==================== Categories ====================
    Route::get('stores/{store}/categories', [CategoryController::class, 'index']);
    Route::get('stores/{store}/categories/home', [CategoryController::class, 'home']);
    Route::get('stores/{store}/categories/slug/{slug}', [CategoryController::class, 'showBySlug']);
    Route::get('stores/{store}/categories/{category}/children', [CategoryController::class, 'children']);
    Route::get('stores/{store}/categories/{category}/price-range', [CategoryController::class, 'priceRange']);
    Route::get('stores/{store}/categories/{category}/filters', [CategoryController::class, 'filters']);
    Route::get('stores/{store}/categories/slug/{slug}/filters', [CategoryController::class, 'filtersBySlug']);

    // ==================== Products ====================
    // OptionalAuth — ручки публичные, но при переданном Bearer-токене отдают
    // персональные признаки товара (isFavorite, isInComparison). Без этого
    // middleware $request->user() на них всегда null, даже с валидным токеном.
    Route::middleware(\App\Http\Middleware\OptionalAuth::class)->group(function () {
        Route::get('stores/{store}/categories/{category}/products', [ProductController::class, 'byCategory']);
        Route::get('stores/{store}/categories/slug/{slug}/products', [ProductController::class, 'byCategorySlug']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('products/slug/{slug}', [ProductController::class, 'showBySlug']);
        Route::get('products/{product}/similar', [ProductController::class, 'similar']);
        Route::get('products/{product}/related', [ProductController::class, 'related']);
        Route::get('products/search/barcode/{barcode}', [ProductController::class, 'searchByBarcode']);
    });

    Route::get('products/{product}/documents', [ProductController::class, 'documents']);

    // ==================== Banners ====================
    Route::get('stores/{store}/banners', [BannerController::class, 'index']);

    // ==================== App settings (настройки приложения для мобилки) ====================
    Route::get('app-settings', [AppSettingsController::class, 'index']);
    Route::get('client-ip', [\App\Http\Controllers\Api\V1\ClientIpController::class, 'show']);

    // ==================== Product Groups ====================
    Route::get('product-group-filters', [\App\Http\Controllers\Api\V1\ProductGroupFilterController::class, 'index']);
    Route::get('product-groups', [ProductGroupController::class, 'home'])->middleware(\App\Http\Middleware\OptionalAuth::class);
    Route::get('stores/{store}/product-groups', [ProductGroupController::class, 'index'])->middleware(\App\Http\Middleware\OptionalAuth::class);
    Route::get('stores/{store}/product-groups/slug/{slug}/products', [ProductGroupController::class, 'productsBySlug'])->middleware(\App\Http\Middleware\OptionalAuth::class);
    Route::get('product-groups/{productGroup}', [ProductGroupController::class, 'show'])->middleware(\App\Http\Middleware\OptionalAuth::class);
    Route::get('product-groups/{productGroup}/products', [ProductGroupController::class, 'products'])->middleware(\App\Http\Middleware\OptionalAuth::class);
    Route::get('product-groups/{productGroup}/siblings', [ProductGroupController::class, 'siblings']);

    // ==================== Promotions ====================
    Route::prefix('promotions')->group(function () {
        Route::get('/', [PromotionController::class, 'index']);
        Route::get('/{promotion}', [PromotionController::class, 'show']);
        Route::get('/{promotion}/products', [PromotionController::class, 'products']);
    });

    // ==================== Delivery ====================
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores/{store}/delivery-slots', [DeliveryController::class, 'slots']);
    });

    // ==================== Delivery Options + Pickup + Tariffs (Public) ====================
    Route::get('delivery/options', [DeliveryOptionsController::class, 'index']);
    Route::prefix('pickup')->group(function () {
        Route::get('cdek', [PickupController::class, 'cdek']);
        Route::get('cdek/{code}', [PickupController::class, 'cdekShow']);
        Route::get('russianpost', [PickupController::class, 'russianPost']);
        Route::get('russianpost/{index}', [PickupController::class, 'russianPostShow'])->where('index', '\d{6}');
        Route::get('cities', [PickupController::class, 'cities']);
    });
    Route::prefix('delivery')->group(function () {
        Route::get('cdek/tariffs', [DeliveryTariffsController::class, 'cdek']);
        Route::get('russianpost/tariffs', [DeliveryTariffsController::class, 'russianPost']);
    });

    // ==================== Pickup Address + Delivery Calculate (Auth) ====================
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('customer/addresses/pickup', [AddressPickupController::class, 'storePickup']);
        Route::get('delivery/calculate', [DeliveryCalculateController::class, 'index']);
    });

    // ==================== Addresses ====================
    Route::middleware('auth:sanctum')->prefix('addresses')->group(function () {
        Route::get('/', [AddressController::class, 'index']);
        Route::get('/active', [AddressController::class, 'active']);
        Route::post('/', [AddressController::class, 'store']);
        Route::put('/{address}', [AddressController::class, 'update']);
        Route::delete('/{address}', [AddressController::class, 'destroy']);
        Route::put('/{address}/active', [AddressController::class, 'setActive']);
    });

    // ==================== Address Photos ====================
    Route::middleware(['auth:sanctum', 'address-objects-enabled'])->group(function () {
        Route::post('addresses/{address}/photos', [\App\Http\Controllers\Api\V1\AddressPhotoController::class, 'store'])
            ->middleware('throttle:10,1,address-photos.store:');
        Route::delete('addresses/{address}/photos/{photo}', [\App\Http\Controllers\Api\V1\AddressPhotoController::class, 'destroy'])
            ->middleware('throttle:30,1,address-photos.destroy:');
    });

    // ==================== Estimates ====================
    Route::middleware('auth:sanctum')->prefix('estimates')->group(function () {
        Route::get('/', [EstimateController::class, 'index']);
        Route::post('/', [EstimateController::class, 'store']);
        Route::get('/{estimate}', [EstimateController::class, 'show']);
        Route::put('/{estimate}', [EstimateController::class, 'update']);
        Route::delete('/{estimate}', [EstimateController::class, 'destroy']);

        Route::post('/{estimate}/items', [EstimateController::class, 'addItem']);
        Route::post('/{estimate}/items/batch', [EstimateController::class, 'batchAddItems']);
        Route::put('/{estimate}/items/{item}', [EstimateController::class, 'updateItem']);
        Route::delete('/{estimate}/items/{item}', [EstimateController::class, 'removeItem']);

        Route::post('/{estimate}/to-cart', [EstimateController::class, 'toCart']);
        Route::post('/{estimate}/clone', [EstimateController::class, 'clone']);
        Route::post('/{estimate}/share', [EstimateController::class, 'share']);
        Route::get('/{estimate}/export', [EstimateController::class, 'export']);
    });

    // Публичный доступ к смете по share-токену (без авторизации)
    Route::get('estimates/shared/{token}', [EstimateController::class, 'showShared']);

    // ==================== Favorites ====================
    Route::middleware('auth:sanctum')->prefix('favorites')->group(function () {
        Route::get('/', [FavoriteController::class, 'index']);
        Route::delete('/', [FavoriteController::class, 'destroy']);
        Route::post('/toggle', [FavoriteController::class, 'toggle']);

        Route::get('/product-groups', [ProductGroupFavoriteController::class, 'index']);
        Route::delete('/product-groups', [ProductGroupFavoriteController::class, 'destroy']);
        Route::post('/product-groups/toggle', [ProductGroupFavoriteController::class, 'toggle']);
    });

    // ==================== Cart ====================
    Route::middleware('auth:sanctum')->prefix('cart')->group(function () {
        Route::get('/', [CartController::class, 'index']);
        Route::delete('/', [CartController::class, 'clear']);

        Route::post('/items', [CartController::class, 'addItem'])->middleware('idempotent');
        Route::post('/items/batch', [CartController::class, 'batchAddItems'])->middleware('idempotent');
        Route::put('/items/{cartItem}', [CartController::class, 'updateItem']);
        Route::delete('/items/{cartItem}', [CartController::class, 'removeItem']);

        Route::post('/promocode', [CartController::class, 'applyPromocode']);
        Route::delete('/promocode', [CartController::class, 'removePromocode']);

        Route::delete('/stores/{store}', [CartController::class, 'clearStoreGroup']);

        Route::post('/store', [CartController::class, 'switchStore']);
    });

    // ==================== Notifications ====================
    Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/read-all', [NotificationController::class, 'readAll']);
        Route::get('/by-announcement/{announcementId}', [NotificationController::class, 'showByAnnouncement']);
        Route::get('/{notification}', [NotificationController::class, 'show']);
        Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
    });

    // ==================== User notification settings ====================
    Route::middleware('auth:sanctum')->prefix('users/me')->group(function () {
        Route::get('notification-settings', [NotificationSettingsController::class, 'show']);
        Route::patch('notification-settings', [NotificationSettingsController::class, 'update']);
    });

    // ==================== Preorders ====================
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('preorders', [PreorderController::class, 'index']);
        Route::post('products/{product}/preorders', [PreorderController::class, 'store'])
            ->middleware('throttle:5,1,preorders.store:');
    });

    // ==================== Product Issue Reports ====================
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('products/{product}/report-issue', [ProductIssueReportController::class, 'store'])
            ->middleware('throttle:5,1,product-issue-reports.store:');
    });

    // ==================== Orders ====================
    Route::middleware('auth:sanctum')->prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store'])
            ->middleware('throttle:5,1,orders.store:'); // 5 запросов в минуту
        Route::get('/active', [OrderController::class, 'getActive']);
        Route::get('/check-supplement', [OrderController::class, 'checkSupplement']);
        Route::get('/{order}', [OrderController::class, 'show']);
        // Поллинг после возврата со страницы оплаты (модуль draft_orders_until_payment).
        // Лимит выше остальных: клиент опрашивает статус раз в пару секунд.
        Route::get('/{order}/payment-status', [OrderController::class, 'paymentStatus'])
            ->middleware('throttle:60,1,orders.payment-status:');
        Route::post('/{order}/cancel', [OrderController::class, 'cancel'])
            ->middleware('throttle:10,1,orders.cancel:'); // 10 запросов в минуту
        Route::post('/{order}/supplement', [OrderController::class, 'supplement'])
            ->middleware('throttle:5,1,orders.supplement:'); // 5 запросов в минуту
        Route::post('/{order}/pay', [OrderController::class, 'pay'])
            ->middleware('throttle:5,1,orders.pay:');
        Route::post('/{order}/refund', [OrderController::class, 'refund'])
            ->middleware('throttle:5,1,orders.refund:');
        Route::get('/{order}/receipts', [\App\Http\Controllers\Api\V1\OrderReceiptController::class, 'index']);
    });

    // ==================== Client Orders (marketplace only) ====================
    Route::middleware(['auth:sanctum', 'marketplace.mode'])->prefix('client-orders')->group(function () {
        Route::get('/', [ClientOrderController::class, 'index']);
        Route::post('/', [ClientOrderController::class, 'store'])->middleware('throttle:5,1,client-orders.store:');
        Route::get('/{clientOrderId}', [ClientOrderController::class, 'show']);
        Route::delete('/{clientOrderId}', [ClientOrderController::class, 'destroy'])
            ->middleware('throttle:10,1,client-orders.destroy:');
    });

    // ==================== Cart delivery-summary (marketplace only) ====================
    Route::middleware(['auth:sanctum', 'marketplace.mode'])
        ->get('cart/delivery-summary', [CartController::class, 'deliverySummary']);

    // ==================== Comparisons ====================
    Route::middleware(['auth:sanctum', 'throttle:60,1,comparisons:'])
        ->prefix('comparisons')
        ->group(function () {
            Route::get('/', [ComparisonController::class, 'index']);
            Route::get('/{categoryId}', [ComparisonController::class, 'show'])
                ->whereNumber('categoryId');
            Route::post('/items', [ComparisonController::class, 'addItem']);
            Route::delete('/items/{productId}', [ComparisonController::class, 'removeItem'])
                ->whereNumber('productId');
            Route::delete('/{categoryId}', [ComparisonController::class, 'clear'])
                ->whereNumber('categoryId');
        });

    // ==================== Payment Cards ====================
    Route::middleware('auth:sanctum')->prefix('payment-cards')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\PaymentCardController::class, 'index']);
        Route::delete('/{paymentCard}', [\App\Http\Controllers\Api\V1\PaymentCardController::class, 'destroy']);
        Route::post('/bind', [\App\Http\Controllers\Api\V1\PaymentCardController::class, 'bind']);
    });

    // ==================== Bonuses ====================
    Route::middleware(['auth:sanctum', 'throttle:30,1,bonuses:'])->group(function () {
        Route::get('/bonuses', [BonusController::class, 'index']);
        Route::get('loyalty-card', [LoyaltyCardController::class, 'show']);
    });

    // ==================== My Reviews (all) ====================
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('reviews/my', [\App\Http\Controllers\Api\V1\MyReviewsController::class, 'index']);
    });

    // ==================== Store Reviews ====================
    Route::get('stores/{store}/reviews', [StoreReviewController::class, 'index']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores/{store}/reviews/my', [StoreReviewController::class, 'my']);
        Route::post('stores/{store}/reviews', [StoreReviewController::class, 'store']);
        Route::put('stores/{store}/reviews', [StoreReviewController::class, 'update']);
    });

    // ==================== Product Reviews ====================
    Route::get('products/{product}/reviews', [ProductReviewController::class, 'index']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('products/{product}/reviews/my', [ProductReviewController::class, 'my']);
        Route::post('products/{product}/reviews', [ProductReviewController::class, 'store']);
        Route::put('products/{product}/reviews', [ProductReviewController::class, 'update']);
    });

    // ==================== Order Reviews ====================
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('orders/{order}/review', [\App\Http\Controllers\Api\V1\OrderReviewController::class, 'show']);
        Route::post('orders/{order}/review', [\App\Http\Controllers\Api\V1\OrderReviewController::class, 'store'])
            ->middleware('throttle:10,1,order-reviews.store:'); // 10 запросов в минуту
        Route::put('orders/{order}/review', [\App\Http\Controllers\Api\V1\OrderReviewController::class, 'update'])
            ->middleware('throttle:10,1,order-reviews.update:'); // 10 запросов в минуту
    });

    // ==================== Search ====================
    Route::prefix('search')->middleware('throttle:100,1,search:')->group(function () {
        Route::get('/products', [SearchController::class, 'products'])->middleware(\App\Http\Middleware\OptionalAuth::class);
        Route::get('/stores/{store}/products', [SearchController::class, 'productsInStore']);
        Route::get('/stores/{store}/filters', [SearchController::class, 'filtersInStore']);
        Route::get('/suggestions', [SearchController::class, 'suggestions']);
        Route::get('/recommendations', [SearchController::class, 'recommendations'])->middleware(\App\Http\Middleware\OptionalAuth::class);
    });
});

// ==================== RabbitMQ Debug ====================
Route::prefix('debug/rabbit')->middleware(RabbitDebugMiddleware::class)->group(function () {
    Route::get('messages', [RabbitDebugController::class, 'index']);
    Route::get('messages/{id}', [RabbitDebugController::class, 'show']);
    Route::get('stats', [RabbitDebugController::class, 'stats']);
    Route::get('routing-keys', [RabbitDebugController::class, 'routingKeys']);
    Route::delete('messages', [RabbitDebugController::class, 'destroy']);
});
