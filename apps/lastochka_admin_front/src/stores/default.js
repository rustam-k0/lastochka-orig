import { defineStore } from "pinia";

export const useDefaultStore = defineStore({
  id: "default",
  state: () => ({
    modals: [],
    MEDIA_ENDPOINT: import.meta.env.VITE_MEDIA_ENDPOINT,
    HOST_ENDPOINT: import.meta.env.VITE_HOST_ENDPOINT,
    convertor_types: {
      CATEGORY: 1,
      PRODUCT: 2,
    },
    types: {
      PHONE: 10,
      EMAIL: 20,
      CODE_WAITING: 1, // Ожидание
      CODE_CONFIRMED: 2, // Подтверждено
      CODE_CAME: 3, // Пришел
      CODE_NOT_CAME: 4, // Не пришел
    },
    modal_img: null,
  }),
});
