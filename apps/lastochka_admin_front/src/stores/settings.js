import { defineStore } from "pinia";

export const useSettingsStore = defineStore({
  id: "settings",
  state: () => {
    const settings = localStorage.getItem("settings");
    return {
      settings: JSON.parse(settings || "{}"),
    };
  },
  actions: {
    set(key, value) {
      this.settings[key] = value;
      this.save();
    },
    save() {
      localStorage.setItem("settings", JSON.stringify(this.settings));
    },
  },
});
