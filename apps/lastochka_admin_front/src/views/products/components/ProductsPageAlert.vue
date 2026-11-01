<template>
  <div
    v-if="selected.length"
    class="products-page__alert"
    :class="{ 'products-page__alert--folded': settings.asideFolded }"
  >
    <span class="products-page__alert-title">
      {{ pluralWord(selected.length, "Выделен", "Выделено", "Выделено") }} {{ selected.length }}
      {{ pluralWord(selected.length, "товар", "товара", "товаров") }}
    </span>
    <button class="btn btn--sm btn--white" :disabled="exporting" @click="exportSelected">
      <IconComponent name="download-2-line" />
      <span>{{ exporting ? "Выгрузка..." : "Выгрузить" }}</span>
    </button>
    <button class="btn btn--sm btn--white" :disabled="exportingAll" @click="exportAll">
      <IconComponent name="download-2-line" />
      <span>{{ exportingAll ? "Выгрузка..." : "Выгрузить все" }}</span>
    </button>
    <span v-if="error" class="products-page__alert-error">{{ error }}</span>
  </div>
</template>

<script>
import { useSettingsStore } from "@/stores/settings.js";
import { pluralWord } from "@/utils.js";
import IconComponent from "@/components/IconComponent.vue";
import Cookies from "js-cookie";

/**
 * Плавающая панель выгрузки товаров в XLSX — по аналогии с OrdersPageAlert.
 * «Выгрузить» — только выделенные строки (product_ids), «Выгрузить все» —
 * весь набор фильтров экрана (filters), не только текущая страница.
 * Бэкенд: admin_back POST /action/export/products.
 */
export default {
  name: "ProductsPageAlert",
  components: { IconComponent },
  props: {
    // ID выделенных товаров
    selected: {
      type: Array,
      default: () => [],
    },
    // Фильтры экрана в формате тела запроса экспорта
    filters: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      exporting: false,
      exportingAll: false,
      // Текст ошибки последней выгрузки (403 без доступа, 422, 500) — показываем в панели
      error: null,
    };
  },
  computed: {
    settings() {
      return useSettingsStore().settings;
    },
  },
  methods: {
    pluralWord,
    async exportSelected() {
      // selected_shop_id нужен и здесь: цена/остаток строки — по выбранному магазину
      const body = { product_ids: this.selected };
      if (this.filters?.selected_shop_id) {
        body.selected_shop_id = this.filters.selected_shop_id;
      }
      await this.downloadExport(body, "exporting");
    },
    async exportAll() {
      // Выгружаем ровно то, что показано на экране: весь набор фильтров
      const body = {};
      Object.entries(this.filters || {}).forEach(([key, value]) => {
        if (value === null || value === undefined || value === "") return;
        if (Array.isArray(value) && !value.length) return;
        body[key] = value;
      });
      await this.downloadExport(body, "exportingAll");
    },
    async downloadExport(body, loadingKey) {
      this[loadingKey] = true;
      this.error = null;
      try {
        const host = import.meta.env.VITE_HOST_ENDPOINT;
        const token = Cookies.get("admin_token");
        const response = await fetch(host + "/action/export/products", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            // Без Accept: application/json бэкенд на 401 уйдёт в redirect на route('login')
            Accept: "application/json",
            Authorization: "Bearer " + token,
          },
          body: JSON.stringify(body),
        });

        if (!response.ok) {
          throw new Error("Export failed: HTTP " + response.status);
        }

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;

        const disposition = response.headers.get("Content-Disposition");
        const filename = disposition ? disposition.split("filename=")[1]?.replace(/"/g, "") : "products.xlsx";

        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
      } catch (e) {
        console.error("Export error:", e);
        this.error = "Не удалось выгрузить: " + (e?.message || "ошибка сети");
      } finally {
        this[loadingKey] = false;
      }
    },
  },
};
</script>

<style lang="stylus">
.products-page {
  &__alert {
    position fixed
    bottom 50px
    right 42px
    padding 32px
    display flex
    gap 16px
    align-items center
    width 100%
    max-width calc(100vw - 396px);
    background var(--white)
    border-radius 8px
    box-shadow: 0 30px 30px rgba(0, 0, 0, 0.07), 0px 0px 30px rgba(0, 0, 0, 0.07), 0px 0px 10px rgba(0, 0, 0, 0.035);
    transition var(--transition)
    z-index 10
  }

  &__alert-title {
    font-size 0.875em
    font-weight 500
  }

  &__alert-error {
    font-size 0.875em
    color var(--red)
  }

  &__alert--folded {
    max-width calc(100vw - 212px);
  }
}
</style>
