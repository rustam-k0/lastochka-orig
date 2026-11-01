<?php

namespace App\GraphQL\Queries\AdminSetting;

use App\Models\AdminSetting;
use Illuminate\Database\Eloquent\Collection;

class AdminSettingQueries
{
    public function all($_, array $args): Collection
    {
        return AdminSetting::all();
    }

    public function byKey($_, array $args): ?AdminSetting
    {
        return AdminSetting::where('key', $args['key'])->first();
    }

    private const PUBLIC_KEYS = ['admin_logo', 'primary_color', 'admin_title'];

    public function publicByKey($_, array $args): ?AdminSetting
    {
        if (!in_array($args['key'], self::PUBLIC_KEYS)) {
            return null;
        }

        return AdminSetting::where('key', $args['key'])->first();
    }

    public function entityCheckboxes(): string
    {
        $defaults = \App\Services\CustomCheckboxService::defaultCheckboxConfig();
        $value = AdminSetting::getValue('entity_checkboxes');
        $stored = $value ? json_decode($value, true) : null;

        if (!is_array($stored)) {
            return json_encode($defaults, JSON_UNESCAPED_UNICODE);
        }

        // Новые сущности из дефолтов автоматически появляются в сохранённой
        // конфигурации (сохранённые значения приоритетны) — иначе Brand/Banner/
        // Sale/Shop никогда не отобразятся на стенде, где блоб уже был сохранён
        // (по образцу projectModules()).
        return json_encode(array_merge($defaults, $stored), JSON_UNESCAPED_UNICODE);
    }

    public function projectModules(): string
    {
        $defaults = $this->getDefaultModulesConfig();
        $value = AdminSetting::getValue('project_modules');

        if (!$value) {
            return json_encode($defaults);
        }

        $stored = json_decode($value, true);
        if (!is_array($stored)) {
            return json_encode($defaults);
        }

        // Новые модули из дефолтов автоматически появляются в сохранённой
        // конфигурации (сохранённые значения приоритетны) — иначе добавленный
        // в код модуль никогда не отобразится в «Настройки → Модули» на стенде,
        // где блоб уже был сохранён.
        $merged = array_merge($defaults, $stored);

        // Фронт получает уже резолвленный anchor_shop_id (fallback на
        // shops.default = 1 внутри сервиса): само поле в блобе регулярно
        // теряется при пересохранении настроек через UI (MID-239).
        $anchorShopId = \App\Services\ModuleConfigService::getCategory1cNetworkAnchorShopId();
        if ($anchorShopId !== null && isset($merged[\App\Services\ModuleConfigService::CATEGORY_1C_NETWORK_KEY])) {
            $merged[\App\Services\ModuleConfigService::CATEGORY_1C_NETWORK_KEY]['anchor_shop_id'] = $anchorShopId;
        }

        return json_encode($merged);
    }

    public function tableColumns(): string
    {
        return json_encode(
            \App\Services\TableColumnsService::resolveConfig(),
            JSON_UNESCAPED_UNICODE
        );
    }

    public function entityFields(): string
    {
        $value = AdminSetting::getValue('entity_fields');

        if ($value) {
            return $value;
        }

        return json_encode($this->getDefaultEntityFieldsConfig());
    }

    private function getDefaultEntityFieldsConfig(): array
    {
        return [
            'Shop' => [
                ['field' => 'is_popular',      'label' => 'Популярный',           'enabled' => true],
                ['field' => 'is_b2b',          'label' => 'B2B',                  'enabled' => true],
                ['field' => 'ignore_stock',    'label' => 'Не учитывать остатки', 'enabled' => true],
                ['field' => 'img',             'label' => 'Фото',                 'enabled' => true],
                ['field' => 'logo_img',        'label' => 'Логотип',              'enabled' => true],
                ['field' => 'background_img',  'label' => 'Задний фон',           'enabled' => true],
                ['field' => 'phone',           'label' => 'Телефон',              'enabled' => true],
                ['field' => 'description',     'label' => 'Описание',             'enabled' => true],
                ['field' => 'email',           'label' => 'Почта',                'enabled' => true],
                ['field' => 'address',         'label' => 'Адрес',                'enabled' => true],
                ['field' => 'schedule',        'label' => 'График работы',        'enabled' => true],
                ['field' => 'employees_table', 'label' => 'Таблица сотрудников',  'enabled' => true],
            ],
            'Category' => [
                ['field' => 'background_img', 'label' => 'Фоновое изображение', 'enabled' => true],
            ],
        ];
    }

    private function getDefaultModulesConfig(): array
    {
        return [
            'bonuses' => [
                'label' => 'Бонусная система',
                'enabled' => true,
                'description' => 'Начисление и списание бонусов',
            ],
            'b2b' => [
                'label' => 'B2B',
                'enabled' => false,
                'description' => 'B2B заказы и партнёры',
            ],
            'supplements' => [
                'label' => 'Добавки',
                'enabled' => true,
                'description' => 'Дополнительные добавки к товарам',
            ],
            'preorders' => [
                'label' => 'Заявки «Под заказ»',
                'enabled' => false,
                'description' => 'Приём заявок на товары без остатка',
            ],
            'brands' => [
                'label' => 'Бренды',
                'enabled' => true,
                'description' => 'Каталог брендов на главной и фильтрация по бренду',
            ],
            'configurable_products' => [
                'label' => 'Вариативные товары',
                'enabled' => false,
                'description' => 'Товары с выбором вариаций (цвет, размер) на витрине',
            ],
            'product_ready_time' => [
                'label' => 'Время готовности товаров',
                'enabled' => false,
                'description' => 'Тег «Доступно с {время}» и ограничение слотов доставки/самовывоза временем готовности товаров',
            ],
            'heavy_orders' => [
                'label' => 'Тяжёлые заказы',
                'enabled' => false,
                'description' => 'Признак «Тяжёлый заказ» по порогу веса магазина и совместная доставка несколькими курьерами',
            ],
            'courier_assignment' => [
                'label' => 'Назначение курьера сборщиком',
                'enabled' => false,
                'description' => 'Сборщик назначает курьера по завершении сборки; курьеры видят только назначенные им заказы, самостоятельное взятие заказов отключено. Включает совместную доставку тяжёлых заказов (2 и более курьеров по порогу веса магазина)',
            ],
            'assembly_cost' => [
                'label' => 'Стоимость сборки',
                'enabled' => false,
                'description' => 'Плоская стоимость сборки заказа на магазин; отображается и взимается на чекауте',
            ],
            'collector_payment' => [
                'label' => 'Списание оплаты сборщиком',
                'enabled' => false,
                'description' => 'Сборщик по кнопке «Списать оплату» списывает итоговую стоимость заказа при завершении сборки (доплата разницы, затем списание холда). При неуспешной доплате заказ остаётся на сборке со статусом «Ожидает доплату», назначение курьера блокируется до успешной оплаты',
            ],
            'honest_sign' => [
                'label' => 'Честный знак (маркировка)',
                'enabled' => false,
                'description' => 'Сканирование кодов маркировки при сборке: для товаров с признаком «Маркируемый товар» сборщик сканирует код каждой единицы, платформа проверяет код в 1С (интеграция «Честный знак») и передаёт коды в 1С вместе с собранным заказом. Без отсканированных кодов маркированную позицию нельзя подтвердить, а сборку завершить',
            ],
            'pickup_skip_collectors' => [
                'label' => 'Самовывоз без сборщиков',
                'enabled' => false,
                'description' => 'Заказы с типом «Самовывоз» не передаются сборщикам: не отображаются в приложении сборщика, пуши о новых заказах не отправляются. Обрабатываются на стороне 1С, в админке отображаются как обычно. Назначение сборщика вручную из админки остаётся доступным',
            ],
            'pickup_card_payment' => [
                'label' => 'Самовывоз: оплата картой онлайн',
                'enabled' => false,
                'description' => 'Чекбокс «Картой онлайн» в блоке «Самовывоз» карточки магазина. При выключенном модуле чекбокс скрыт, а оплата картой онлайн вычищается из способов оплаты самовывоза при сохранении магазина',
            ],
            'draft_orders_until_payment' => [
                'label' => 'Заказ только после оплаты',
                'enabled' => false,
                'description' => 'При оплате картой онлайн заказ создаётся черновиком и попадает в работу только после подтверждения оплаты. Клиент, закрывший страницу оплаты, сохраняет корзину, а неоплаченный заказ закрывается сам по сроку ожидания и возвращает товар в остаток. На оплату наличными и сохранённой картой не влияет',
            ],
            'checkout_without_slots' => [
                'label' => 'Оформление доставки без слотов',
                'enabled' => false,
                'description' => 'Клиент оформляет курьерскую доставку без выбора временного слота — доставка в ближайшее время после оформления. Витрина принимает такие заказы без слота (переданный слот учитывается), сайт и мобильное приложение прячут выбор времени, в админке для доставочных заказов скрываются время в колонке «Слот доставки», блок «Дата и время доставки» в карточке заказа и «Количество заказов на слот» в карточке магазина. Самовывоз модулем не затрагивается: клиент выбирает время выдачи, слот обязателен',
            ],
            'price_tiers' => [
                'label' => 'Цены от количества',
                'enabled' => false,
                'description' => 'Ступенчатая цена товара в зависимости от количества в позиции: лесенка «от N единиц» задаётся по магазинам из 1С или в карточке товара, применяется в корзине и заказе, пересчитывается по фактическому количеству после сборки. При выключенном флаге поведение платформы не меняется. Включает только суперадминистратор',
            ],
            'delivery_price_ranges' => [
                'label' => 'Диапазоны стоимости доставки',
                'enabled' => false,
                'description' => 'Стоимость доставки зависит от суммы заказа: у зоны задаются диапазоны «от суммы: цена».',
            ],
            // Вкладки карточки магазина. Тумблер скрывает вкладку в АП,
            // backend-функциональность (GraphQL, синк на витрину) не глушит —
            // как у модуля bonuses. Включены по умолчанию: на существующих
            // стендах после деплоя ничего не должно пропасть.
            'shop_delivery_pickup' => [
                'label' => 'Магазин: доставка и самовывоз',
                'enabled' => true,
                'description' => 'Вкладка «Доставка и самовывоз» в карточке магазина (зоны доставки, настройки самовывоза)',
            ],
            'shop_assembly_map' => [
                'label' => 'Магазин: карта для сборщика',
                'enabled' => true,
                'description' => 'Вкладка «Карта магазина для сборщика» (секции и время сборки)',
            ],
            'shop_stop_lists' => [
                'label' => 'Магазин: стоп-листы',
                'enabled' => true,
                'description' => 'Вкладка «Стоп-листы» в карточке магазина («Не отображать» и «Не доставлять»)',
            ],
            'shop_statistics' => [
                'label' => 'Магазин: статистика',
                'enabled' => true,
                'description' => 'Вкладка «Статистика» в карточке магазина (выручка, количество заказов, активные заказы)',
            ],
        ];
    }

    private function getDefaultCheckboxConfig(): array
    {
        return \App\Services\CustomCheckboxService::defaultCheckboxConfig();
    }
}
