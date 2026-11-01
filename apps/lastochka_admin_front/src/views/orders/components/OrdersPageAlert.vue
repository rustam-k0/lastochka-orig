<template>
  <div
    v-if="selected.length"
    class="orders-page__alert"
    :class="{ 'orders-page__alert--folded': settings.asideFolded }"
  >
    <span class="orders-page__alert-title">
      {{ pluralWord(selected.length, "Выделен", "Выделено", "Выделено") }} {{ selected.length }}
      {{ pluralWord(selected.length, "элемент", "элемента", "элементов") }}
    </span>
    <SelectComponent
      v-if="canExport"
      class="orders-page__alert-format"
      placeholder="Формат"
      label-name="title"
      :model-value="format"
      :options="formatOptions"
      @update:model-value="updateFormat"
    />
    <button v-if="canExport" class="btn btn--sm btn--white" :disabled="exporting" @click="exportSelected">
      <IconComponent name="download-2-line" />
      <span>{{ exporting ? 'Выгрузка...' : 'Выгрузить' }}</span>
    </button>
    <button v-if="canExport" class="btn btn--sm btn--white" :disabled="exportingAll" @click="exportAll">
      <IconComponent name="download-2-line" />
      <span>{{ exportingAll ? 'Выгрузка...' : 'Выгрузить все' }}</span>
    </button>
  </div>
</template>

<script>
import { useSettingsStore } from "@/stores/settings.js";
import { useAuthStore } from "@/stores/auth.js";
import { pluralWord } from "@/utils.js";
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import Cookies from "js-cookie";

export default {
  name: "OrdersPageAlert",
  components: { IconComponent, SelectComponent },
  props: {
    selected: {
      type: Array,
      default: () => [],
    },
    filters: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      exporting: false,
      exportingAll: false,
      formatOptions: [
        { id: "items", title: "По товарам" },
        { id: "summary", title: "Сводно" },
      ],
      format: { id: "items", title: "По товарам" },
    };
  },
  computed: {
    settings() {
      return useSettingsStore().settings;
    },
    canExport() {
      return useAuthStore().canOrderAction("orders.export");
    },
  },
  methods: {
    pluralWord,
    updateFormat(value) {
      // Селект clearable не выставлен, но защищаемся от сброса в null
      this.format = value || this.formatOptions[0];
    },
    async exportSelected() {
      const orderIds = this.selected.map((item) => item.id);
      await this.downloadExport({ order_ids: orderIds }, "exporting");
    },
    async exportAll() {
      // Выгружаем ровно то, что показано на экране: весь набор фильтров,
      // а не только ID заказа и магазин
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
      try {
        const host = import.meta.env.VITE_HOST_ENDPOINT;
        const token = Cookies.get("admin_token");
        const response = await fetch(host + "/purchase/admin/orders/export", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: "Bearer " + token,
          },
          body: JSON.stringify({ ...body, format: this.format.id }),
        });

        if (!response.ok) {
          throw new Error("Export failed");
        }

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;

        const disposition = response.headers.get("Content-Disposition");
        const filename = disposition
          ? disposition.split("filename=")[1]?.replace(/"/g, "")
          : "orders.xlsx";

        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
      } catch (e) {
        console.error("Export error:", e);
      } finally {
        this[loadingKey] = false;
      }
    },
  },
};
</script>

<style lang="stylus">
.orders-page {
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

  &__alert-format {
    min-width 180px
    max-width 200px
  }

  &__alert--folded {
    max-width calc(100vw - 212px);
  }
}
</style>
