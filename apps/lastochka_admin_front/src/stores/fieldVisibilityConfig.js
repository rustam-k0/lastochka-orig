import { defineStore } from "pinia";

const FIELD_DEFAULTS = {
  Shop: {
    type_id: "Тип магазина",
    is_popular: "Популярный",
    is_b2b: "B2B",
    ignore_stock: "Не учитывать остатки",
    img: "Фото",
    logo_img: "Логотип",
    background_img: "Задний фон",
    phone: "Телефон",
    description: "Описание",
    email: "Почта",
    address: "Адрес",
    schedule: "График работы",
    employees_table: "Таблица сотрудников",
    categories_assembly_time: "Время сборки по категориям",
  },
  Category: {
    background_img: "Фоновое изображение",
  },
};

export const useFieldVisibilityConfigStore = defineStore("fieldVisibilityConfig", {
  state: () => ({
    config: null,
    loaded: false,
  }),
  getters: {
    isEnabled() {
      return (entity, field) => {
        // Аналогично checkboxConfig: до ответа entityFields поле не рендерим,
        // иначе скрытое поле мигает. Предзагрузка — в router/parts/guard.js.
        if (!this.loaded) return false;
        if (!this.config) return true;
        const items = this.config[entity];
        if (!items) return true;
        const item = items.find((c) => c.field === field);
        return item ? item.enabled : true;
      };
    },
    getEntityConfig() {
      return (entity) => {
        if (!this.config) {
          return Object.entries(FIELD_DEFAULTS[entity] || {}).map(([field, label]) => ({
            field,
            label,
            enabled: true,
          }));
        }
        return this.config[entity] || [];
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

export { FIELD_DEFAULTS };
