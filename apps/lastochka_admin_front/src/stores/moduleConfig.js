import { defineStore } from "pinia";

const MODULE_DEFAULTS = {
  bonuses: {
    label: "Бонусная система",
    enabled: true,
    description: "Начисление и списание бонусов",
  },
  b2b: {
    label: "B2B",
    enabled: false,
    description: "B2B заказы и партнёры",
  },
  supplements: {
    label: "Добавки",
    enabled: true,
    description: "Дополнительные добавки к товарам",
  },
  product_group_filters: {
    label: "Фильтры комплексных решений",
    enabled: false,
    description: "Фасетная навигация по комплексным решениям (Комната → Размер → Стиль)",
  },
  product_group_quantity: {
    label: "Количество товаров в группе",
    enabled: false,
    description: "Указание количества для каждого товара в товарной группе",
  },
  preorders: {
    label: "Заявки «Под заказ»",
    enabled: false,
    description: "Приём и обработка заявок на товары под заказ",
  },
  brands: {
    label: "Бренды",
    enabled: true,
    description: "Каталог брендов на главной и фильтрация по бренду",
  },
  product_documents: {
    label: "Документы товаров",
    enabled: false,
    description: "Сертификаты, паспорта и другая документация к товарам",
  },
  product_video: {
    label: "Видео товаров",
    enabled: false,
    description: "Видео в карточке товара (автоплей в галерее)",
  },
  configurable_products: {
    label: "Вариативные товары",
    enabled: false,
    description: "Объединение товаров в вариативные с выбором атрибутов (цвет, размер и т.д.)",
  },
  product_issue_reports: {
    label: "Обращения по товарам",
    enabled: false,
    description: "Приём и обработка обращений клиентов о неточностях в карточке товара",
  },
  product_ready_time: {
    label: "Время готовности товаров",
    enabled: false,
    description:
      "Тег «Доступно с {время}» и ограничение слотов доставки/самовывоза временем готовности товаров",
  },
  product_composition: {
    label: "Состав товаров",
    enabled: true,
    description: "Секция «Состав» в карточке товара (КБЖУ, ингредиенты)",
  },
  product_params: {
    label: "Параметры товаров",
    enabled: true,
    description: "Секция «Параметры» в карточке товара (условия хранения, срок годности)",
  },
  "addresses.objects": {
    label: "Адрес-объект",
    enabled: false,
    description:
      "Расширенный адрес доставки для коммерческих/строительных объектов: контакты на месте, лифт, пропуск, время приёма, дни работы, фото въезда",
  },
  marketplace_mode: {
    label: "Режим работы магазина",
    type: "select",
    value: "standard",
    options: [
      {
        value: "standard",
        label: "Стандарт",
        description:
          "Один магазин или мульти-стор с видимыми партнёрами. Группировка корзины по магазинам, один заказ = один магазин.",
      },
      {
        value: "marketplace",
        label: "Скрытый маркетплейс",
        description:
          "Партнёры скрыты от клиента. Корзина группируется по датам доставки. Один клиентский заказ → N Order-ов на бэке.",
      },
    ],
    description: "Определяет как магазин ведёт себя для клиента.",
  },
  assembly_zones: {
    label: "Разделение заказов на подзаказы по зонам сборки",
    enabled: false,
    description:
      "Автоматически расщепляет заказ на подзаказы по зонам сборки товаров, если у категорий проставлена зона. При выключенном модуле поле «Зона сборки» в категориях и раздел «Зоны сборки» в сайдбаре скрываются.",
  },
  category_1c_integration: {
    label: "Категории 1С",
    enabled: false,
    description: "Отдельный справочник категорий из 1С и экран привязки 1С↔витринные категории",
  },
  category_1c_network_mapping: {
    label: "Сетевой маппинг категорий 1С",
    enabled: false,
    description:
      "Единый справочник и маппинг категорий 1С для всей сети магазинов (якорный магазин задаётся ключом anchor_shop_id).",
  },
  // Вкладки карточки магазина: тумблер скрывает вкладку в АП,
  // backend-функциональность не глушится (как у bonuses).
  shop_delivery_pickup: {
    label: "Магазин: доставка и самовывоз",
    enabled: true,
    description:
      "Вкладка «Доставка и самовывоз» в карточке магазина (зоны доставки, настройки самовывоза)",
  },
  shop_assembly_map: {
    label: "Магазин: карта для сборщика",
    enabled: true,
    description: "Вкладка «Карта магазина для сборщика» (секции и время сборки)",
  },
  shop_stop_lists: {
    label: "Магазин: стоп-листы",
    enabled: true,
    description: "Вкладка «Стоп-листы» в карточке магазина («Не отображать» и «Не доставлять»)",
  },
  shop_statistics: {
    label: "Магазин: статистика",
    enabled: true,
    description:
      "Вкладка «Статистика» в карточке магазина (выручка, количество заказов, активные заказы)",
  },
  aggregator_enabled: {
    label: "Режим агрегатора (Платформенные товары + офферы)",
    enabled: false,
    description:
      "Дедупликация товаров по штрихкоду: один товар на платформе, N магазинов-поставщиков. При выключенном флаге разделы «Платформенные товары» и «Карантин» скрыты, импорт офферов и split заказов по магазинам не выполняются.",
  },
  network_catalog: {
    label: "Сетевой каталог (одна карточка товара, цены и остатки по магазинам)",
    enabled: false,
    description:
      "Режим торговой сети с единой 1С: карточка товара одна на всю сеть (дедупликация по GUID), цены и остатки хранятся пер-магазин. При выключенном флаге поведение платформы не меняется.",
  },
  onec_card_master: {
    label: "1С ведёт карточку товара",
    enabled: false,
    description:
      "Только для прямой публикации 1С в RabbitMQ. Данные карточки из 1С перезаписывают артикул, активность, фото и дописывают характеристики. При обмене через 1c_parser не включать. При выключенном флаге поведение платформы не меняется.",
  },
  onec_shops_master: {
    label: "1С ведёт магазины и сотрудников",
    enabled: false,
    description:
      "Только для прямой публикации 1С в RabbitMQ. Данные магазинов из 1С перезаписывают статус, контакты, координаты, слот, график и порог тяжёлого заказа; принимаются сотрудники и их графики. При выключенном флаге поведение платформы не меняется.",
  },
  price_tiers: {
    label: "Цены от количества",
    enabled: false,
    description:
      "Ступенчатая цена товара в зависимости от количества в позиции: лесенка «от N единиц» задаётся по магазинам из 1С или в карточке товара, применяется в корзине и заказе, пересчитывается по фактическому количеству после сборки. При выключенном флаге поведение платформы не меняется. Включает только суперадминистратор",
  },
  delivery_price_ranges: {
    label: "Диапазоны стоимости доставки",
    enabled: false,
    description: "Стоимость доставки зависит от суммы заказа: у зоны задаются диапазоны «от суммы: цена».",
  },
  collector_payment: {
    label: "Списание оплаты сборщиком",
    enabled: false,
    description:
      "Сборщик по кнопке «Списать оплату» списывает итоговую стоимость заказа при завершении сборки. При неуспешной доплате заказ ожидает доплату, назначение курьера блокируется",
  },
  pickup_card_payment: {
    label: "Самовывоз: оплата картой онлайн",
    enabled: false,
    description:
      "Чекбокс «Картой онлайн» в блоке «Самовывоз» карточки магазина. При выключенном модуле чекбокс скрыт, а оплата картой онлайн вычищается из способов оплаты самовывоза при сохранении магазина",
  },
  pickup_skip_collectors: {
    label: "Самовывоз без сборщиков",
    enabled: false,
    description:
      "Заказы с типом «Самовывоз» не передаются сборщикам: не отображаются в приложении сборщика, пуши о новых заказах не отправляются. Обрабатываются на стороне 1С, в админке отображаются как обычно. Назначение сборщика вручную из админки остаётся доступным",
  },
  draft_orders_until_payment: {
    label: "Заказ только после оплаты",
    enabled: false,
    description:
      "При оплате картой онлайн заказ создаётся черновиком и попадает в работу только после подтверждения оплаты. Клиент, закрывший страницу оплаты, сохраняет корзину, а неоплаченный заказ закрывается сам по сроку ожидания и возвращает товар в остаток. На оплату наличными и сохранённой картой не влияет",
  },
  honest_sign: {
    label: "Честный знак (маркировка)",
    enabled: false,
    description:
      "Сканирование кодов маркировки при сборке: для товаров с признаком «Маркируемый товар» сборщик сканирует код каждой единицы, платформа проверяет код в 1С (интеграция «Честный знак») и передаёт коды в 1С вместе с собранным заказом. Без отсканированных кодов маркированную позицию нельзя подтвердить, а сборку завершить",
  },
  checkout_without_slots: {
    label: "Оформление доставки без слотов",
    enabled: false,
    description:
      "Клиент оформляет курьерскую доставку без выбора временного слота — доставка в ближайшее время после оформления. Витрина принимает такие заказы без слота (переданный слот учитывается), сайт и мобильное приложение прячут выбор времени, в админке для доставочных заказов скрываются время в колонке «Слот доставки», блок «Дата и время доставки» в карточке заказа и «Количество заказов на слот» в карточке магазина. Самовывоз модулем не затрагивается: клиент выбирает время выдачи, слот обязателен",
  },
};

export const useModuleConfigStore = defineStore("moduleConfig", {
  state: () => ({
    config: null,
    loaded: false,
  }),
  getters: {
    isModuleEnabled() {
      return (moduleKey) => {
        if (!this.config) {
          return MODULE_DEFAULTS[moduleKey]?.enabled ?? false;
        }
        const module = this.config[moduleKey];
        return module ? module.enabled : false;
      };
    },
    getModuleLabel() {
      return (moduleKey) => {
        const fallback = MODULE_DEFAULTS[moduleKey]?.label || moduleKey;
        if (!this.config) return fallback;
        const module = this.config[moduleKey];
        return module?.label || fallback;
      };
    },
    getModuleDescription() {
      return (moduleKey) => {
        const fallback = MODULE_DEFAULTS[moduleKey]?.description || "";
        if (!this.config) return fallback;
        const module = this.config[moduleKey];
        return module?.description || fallback;
      };
    },
    getModuleValue() {
      return (moduleKey) => {
        const module = this.config?.[moduleKey] || MODULE_DEFAULTS[moduleKey];
        if (!module) return null;
        if (module.type === "select") return module.value;
        // Backward compat для boolean модулей
        return module.enabled ? true : false;
      };
    },
    allModules() {
      if (!this.config) {
        return Object.entries(MODULE_DEFAULTS).map(([key, val]) => ({
          key,
          ...val,
        }));
      }
      return Object.entries(this.config).map(([key, val]) => ({
        key,
        ...val,
      }));
    },
  },
  actions: {
    setConfig(jsonString) {
      try {
        this.config = JSON.parse(jsonString);
      } catch {
        this.config = null;
      }
      this.loaded = true;
    },
  },
});

export { MODULE_DEFAULTS };
