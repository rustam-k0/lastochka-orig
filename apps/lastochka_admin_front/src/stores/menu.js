import { defineStore } from "pinia";

export const useMenuStore = defineStore("menu", {
  state: () => ({
    items: [],
    loaded: false,
  }),
  getters: {
    titleFor() {
      return (name, fallback = "") => {
        const item = this.items.find((i) => i.name === name);
        return item?.title || fallback;
      };
    },
    isVisible() {
      return (name) => {
        // До загрузки меню — скрыто: иначе гейтящийся элемент мигает
        // (отрисовался → пункт оказался выключен → исчез). При сбое запроса
        // AsideComponent вызывает setItems([]) — сработает fallback ниже
        if (!this.loaded) return false;
        const item = this.items.find((i) => i.name === name);
        // Пункт не заведён сидером на форке — считаем видимым
        return item ? !!item.is_visible : true;
      };
    },
  },
  actions: {
    setItems(items) {
      this.items = items || [];
      this.loaded = true;
    },
  },
});
