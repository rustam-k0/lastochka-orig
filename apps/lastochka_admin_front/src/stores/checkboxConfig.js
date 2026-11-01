import { defineStore } from "pinia";

const CHECKBOX_DEFAULTS = {
  Product: {
    is_culinary: "Кулинария",
    sugar_free: "Без сахара",
    on_search_page: "Выводить на странице поиска",
    served_warm: "Подаётся тёплым",
    pickup_only: "Только самовывоз",
    adult_only: "Товар 18+",
    is_marked: "Маркируемый товар (Честный знак)",
    is_new: "Новинка",
    is_hurry_up: "Успей купить",
  },
  Category: {
    important: "Важные категории",
    is_cooking: "Кулинария",
    is_perishable: "Скоропортящийся / заморозка",
    show_on_main: "Показывать на главной странице",
  },
  MerchantProductGroup: {
    show_on_main: "Показывать на главной",
    show_on_merchant_page: "Показывать на странице магазина",
    show_in_merchant_catalog: "Показывать в каталоге магазина",
    show_on_search: "Выводить на странице поиска",
    is_recommended: "Рекомендуем",
    is_cart_page: "Выводить на странице корзины",
  },
  Story: {
    is_quick_category: "Быстрая категория",
  },
  Brand: {},
  MerchantBanner: {},
  MerchantSale: {},
  Shop: {},
};

export const useCheckboxConfigStore = defineStore("checkboxConfig", {
  state: () => ({
    config: null,
    loaded: false,
  }),
  getters: {
    isEnabled() {
      return (entity, field) => {
        // Пока конфиг не пришёл — чекбокс не рендерим. Иначе отключённый в настройках
        // чекбокс успевает отрисоваться по fail-open дефолту и исчезает после ответа
        // entityCheckboxes (мигание). Предзагрузка — в router/parts/guard.js.
        if (!this.loaded) return false;
        if (!this.config) return true;
        const items = this.config[entity];
        if (!items) return true;
        const item = items.find((c) => c.field === field);
        return item ? item.enabled : true;
      };
    },
    getLabel() {
      return (entity, field) => {
        const fallback = CHECKBOX_DEFAULTS[entity]?.[field] || field;
        if (!this.config) return fallback;
        const items = this.config[entity];
        if (!items) return fallback;
        const item = items.find((c) => c.field === field);
        return item?.label || fallback;
      };
    },
    getEntityConfig() {
      return (entity) => {
        if (!this.config) {
          return Object.entries(CHECKBOX_DEFAULTS[entity] || {}).map(([field, label]) => ({
            field,
            label,
            enabled: true,
          }));
        }
        return this.config[entity] || [];
      };
    },
    customCheckboxes() {
      return (entity) => {
        if (!this.config) return [];
        const items = this.config[entity];
        if (!items) return [];
        return items.filter((c) => c.custom && c.enabled);
      };
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

export { CHECKBOX_DEFAULTS };
