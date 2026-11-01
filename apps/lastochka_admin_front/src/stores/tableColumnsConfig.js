import { defineStore } from "pinia";

/**
 * Дефолтный состав колонок — зеркало TableColumnsService::defaultConfig()
 * на бэкенде. Нужен как fallback для «Настройки → Колонки таблиц», пока
 * (или если) конфиг не приехал: сами таблицы объявляют свои колонки локально
 * и при пустом конфиге показывают их как есть.
 */
export const TABLE_COLUMN_DEFAULTS = {
  products: [
    { field: "isActive", label: "Индикатор статуса", enabled: true, system: true },
    { field: "select", label: "Выбор строк", enabled: true, system: true },
    { field: "photo", label: "Фото", enabled: true, system: false },
    { field: "title", label: "Название", enabled: true, system: false },
    { field: "categories", label: "Категория", enabled: true, system: false },
    { field: "default_price", label: "Стоимость", enabled: true, system: false },
    { field: "remains", label: "Остатки", enabled: true, system: false },
    { field: "fixed_stock", label: "Фиксированные остатки", enabled: true, system: false },
    { field: "fill_percent", label: "Заполненность", enabled: true, system: false },
    { field: "active", label: "Активировать", enabled: true, system: false },
  ],
  orders: [
    { field: "id", label: "№ заказа", enabled: true, system: false },
    { field: "created_at", label: "Дата", enabled: true, system: false },
    { field: "client", label: "Покупатель", enabled: true, system: false },
    { field: "address", label: "Адрес", enabled: true, system: false },
    { field: "status_id", label: "Статус", enabled: true, system: false },
    { field: "shop", label: "Магазин", enabled: true, system: false },
    { field: "type_id", label: "Тип", enabled: true, system: false },
    { field: "slot", label: "Слот доставки", enabled: true, system: false },
    { field: "payment_type_id", label: "Способ оплаты", enabled: true, system: false },
    { field: "payment_status", label: "Статус оплаты", enabled: true, system: false },
    { field: "amount", label: "Сумма", enabled: true, system: true },
    { field: "collector", label: "Сборщик", enabled: true, system: false },
    { field: "couriers", label: "Курьер", enabled: true, system: false },
    { field: "is_heavy", label: "Тяжелый", enabled: true, system: false },
    { field: "action", label: "Действия", enabled: true, system: true },
  ],
};

export const TABLE_TITLES = {
  products: "Товары",
  orders: "Заказы",
};

export const useTableColumnsConfigStore = defineStore("tableColumnsConfig", {
  state: () => ({
    config: null,
    loaded: false,
  }),
  getters: {
    /**
     * Накладывает сохранённый порядок и видимость на колонки, объявленные в
     * компоненте таблицы. Заголовок, ширина, sortable/selectable остаются из
     * кода — блоб хранит только состав и порядок.
     *
     * Fail-open: пока конфиг не загружен (или в нём нет этой таблицы) таблица
     * рисуется ровно так, как объявлена в компоненте.
     */
    applyTo() {
      return (table, columns) => {
        const order = this.config?.[table];
        if (!Array.isArray(order) || !order.length) {
          return columns;
        }

        const rest = new Map((columns || []).map((c) => [c.field, c]));
        const result = [];

        for (const item of order) {
          const column = rest.get(item.field);
          if (!column) continue;
          rest.delete(item.field);
          // Служебные колонки (чекбокс выбора, кнопка действий) скрыть нельзя —
          // бэкенд их тоже не отдаёт выключенными, это защита от битого блоба.
          if (item.enabled === false && !item.system && !column.system) continue;
          result.push(column);
        }

        // Колонки, которых ещё нет в конфиге (добавлены в код позже) — в конец,
        // чтобы новая колонка не пропадала до пересохранения настроек.
        for (const column of rest.values()) {
          result.push(column);
        }

        return result;
      };
    },
    /** Конфигурация одной таблицы для экрана настроек. */
    getTableConfig() {
      return (table) => {
        const stored = this.config?.[table];
        if (Array.isArray(stored) && stored.length) {
          return stored.map((c) => ({ ...c }));
        }
        return (TABLE_COLUMN_DEFAULTS[table] || []).map((c) => ({ ...c }));
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
