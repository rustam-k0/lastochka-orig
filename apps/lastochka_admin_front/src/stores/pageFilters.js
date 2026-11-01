import { defineStore } from "pinia";

export const useFiltersStore = defineStore({
  id: "filters",
  state: () => {
    const filters = localStorage.getItem("filters");
    return {
      filters: JSON.parse(filters || "{}"),
    };
  },
  actions: {
    get(id) {
      return this.filters[id];
    },
    set(id, updatedFilters) {
      this.filters[id] = { ...this.filters[id], ...updatedFilters };
      localStorage.setItem("filters", JSON.stringify(this.filters));
    },
  },
});
