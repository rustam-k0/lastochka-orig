<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ImageResource;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;

/**
 * Настройки мобильного приложения. Публичная ручка — мобилка дёргает
 * при запуске, до авторизации.
 */
final class AppSettingsController extends Controller
{
    /**
     * Пункты блока «Дополнительная информация» карточки товара.
     * Порядок массива = порядок пунктов в приложении. Пункт «Документы»
     * сюда не входит — мобилка берёт его из GET /products/{product}/documents.
     */
    private const ADDITIONAL_INFO_ITEMS = [
        [
            'setting' => 'product_info_delivery_payment',
            'key' => 'deliveryAndPayment',
            'title' => 'Доставка и оплата',
        ],
        [
            'setting' => 'product_info_return_policy',
            'key' => 'returnPolicy',
            'title' => 'О возврате',
        ],
        [
            'setting' => 'product_info_defective_product',
            'key' => 'defectiveProduct',
            'title' => 'Если привезли не тот товар или товар с браком',
        ],
    ];

    public function index(): JsonResponse
    {
        $bannerPath = StorefrontSetting::getValue('app_launch_banner');

        return response()->json([
            'data' => [
                'launchBanner' => $bannerPath
                    ? ['path' => ImageResource::toFullUrl($bannerPath)]
                    : null,
                'additionalInfo' => $this->additionalInfo(),
                'contacts' => $this->contacts(),
                // Опции оформления заказа: чекбоксы чекаута,
                // коды выбранных уходят в POST /orders → checkoutOptions
                'checkoutOptions' => app(\App\Services\Checkout\CheckoutOptionsService::class)->enabled(),
                // Модуль «Оформление заказа без слотов»: при true клиент
                // не показывает выбор слота, POST /orders принимает без deliverySlotId
                'checkoutWithoutSlots' => app(\App\Services\Settings\ModuleConfigService::class)->isCheckoutWithoutSlotsEnabled(),
                // Модуль «Цены от количества»: при true у товаров и позиций
                // корзины заполнено поле priceTiers, цена позиции зависит от количества
                'priceTiers' => app(\App\Services\Settings\ModuleConfigService::class)->isPriceTiersEnabled(),
            ],
        ]);
    }

    /**
     * Контакты и ссылки приложения (MENDEV-15). Источники — json-настройки
     * storefront_settings: contact_phones, contact_emails, social_networks,
     * app_links (прилетают из админки событиями admin_settings).
     *
     * @return array{
     *     phones: list<string>,
     *     emails: list<string>,
     *     socialNetworks: list<array{name: string, url: string, logo: string|null}>,
     *     appLinks: array{appStore: string|null, googlePlay: string|null, ruStore: string|null}
     * }
     */
    private function contacts(): array
    {
        $phones = StorefrontSetting::getValue('contact_phones', []);
        $emails = StorefrontSetting::getValue('contact_emails', []);
        $socialNetworks = StorefrontSetting::getValue('social_networks', []);
        $appLinks = StorefrontSetting::getValue('app_links', []);

        $socials = [];
        foreach (is_array($socialNetworks) ? $socialNetworks : [] as $item) {
            if (! is_array($item) || empty($item['url'])) {
                continue;
            }

            $logo = $item['logo'] ?? null;

            $socials[] = [
                'name' => (string) ($item['name'] ?? ''),
                'url' => (string) $item['url'],
                // Логотип: полный URL отдаём как есть, локальный путь
                // (загружен через файловую настройку) — резолвим в полный
                'logo' => is_string($logo) && $logo !== ''
                    ? (str_starts_with($logo, 'http') ? $logo : ImageResource::toFullUrl($logo))
                    : null,
            ];
        }

        $links = is_array($appLinks) ? $appLinks : [];

        return [
            'phones' => array_values(array_filter(is_array($phones) ? $phones : [], 'is_string')),
            'emails' => array_values(array_filter(is_array($emails) ? $emails : [], 'is_string')),
            'socialNetworks' => $socials,
            'appLinks' => [
                'appStore' => $links['appStore'] ?? $links['app_store'] ?? null,
                'googlePlay' => $links['googlePlay'] ?? $links['google_play'] ?? null,
                'ruStore' => $links['ruStore'] ?? $links['ru_store'] ?? null,
            ],
        ];
    }

    /**
     * Заполненные пункты «Дополнительной информации»: пустые скрываются.
     *
     * @return array<int, array{key: string, title: string, text: string}>
     */
    private function additionalInfo(): array
    {
        $items = [];

        foreach (self::ADDITIONAL_INFO_ITEMS as $item) {
            $text = StorefrontSetting::getValue($item['setting']);

            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $items[] = [
                'key' => $item['key'],
                'title' => $item['title'],
                'text' => $text,
            ];
        }

        return $items;
    }
}
