<template>
  <main class="page quarantine-page">
    <div class="quarantine-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="quarantine-page__filters">
        <select
          v-model="shopId"
          @change="onFilter"
          class="quarantine-page__filter"
          title="Магазин"
          aria-label="Магазин"
        >
          <option :value="null">Магазин: все</option>
          <option v-for="shop in shops" :key="shop.id" :value="shop.id">
            {{ shop.title }}
          </option>
        </select>
        <select v-model="selected" @change="onFilter" class="quarantine-page__filter">
          <option v-for="opt in options" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
        <button class="btn btn--md" :disabled="exporting" @click="exportToExcel">
          <IconComponent name="download-2-line" />
          <span>{{ exporting ? "Готовим файл…" : "Скачать" }}</span>
        </button>
      </div>
    </div>

    <TableComponent
      v-if="rows"
      :columns="columns"
      :rows="rows.data || []"
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
      :total="rows.paginatorInfo.total"
      :first="first"
      :page="page"
      :loading="loading"
      pagination
    >
      <template #col="{ col, row }">
        <!-- Карантин агрегатора -->
        <template v-if="col.field === 'incoming_title'">
          <router-link
            v-if="productLink(row)"
            :to="productLink(row)"
            class="quarantine-page__product-link"
          >{{ row.incoming_title || "—" }}</router-link>
          <span v-else>{{ row.incoming_title || "—" }}</span>
        </template>
        <span v-if="col.field === 'reason'">{{ reasonLabel(row.reason) }}</span>
        <!-- Проблемные товары 1С / union -->
        <template v-if="col.field === 'name'">
          <router-link
            v-if="productLink(row)"
            :to="productLink(row)"
            class="quarantine-page__product-link"
          >{{ row.name || "—" }}</router-link>
          <span v-else>{{ row.name || "—" }}</span>
        </template>
        <span v-if="col.field === 'sku'">{{ row.sku || "—" }}</span>
        <span v-if="col.field === 'barcode'">{{ row.barcode || "—" }}</span>
        <!-- Общие -->
        <span v-if="col.field === 'shop'">{{ row.shop_title || row.shop_id || "—" }}</span>
        <span v-if="col.field === 'created_at'">{{ formatDate(row.created_at) }}</span>
        <div v-if="col.field === 'action'" class="quarantine-page__actions">
          <button
            v-if="row.reason === 'no_ean' || row.source === 'quarantine'"
            class="btn btn--sm"
            @click="openSetBarcode(row)"
          >Проставить штрихкод</button>
          <button
            v-if="row.reason === 'double_match'"
            class="btn btn--sm"
            @click="openMerge(row)"
          >Объединить</button>
        </div>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import { shallowRef } from "vue";
import Cookies from "js-cookie";
import { useDefaultStore } from "@/stores/default.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import TableComponent from "@/components/table/index.vue";
import IconComponent from "@/components/IconComponent.vue";
import dayjs from "dayjs";
import QUARANTINE from "@/graphql/pages/Aggregator/aggregatorQuarantinePaginate.graphql";
import PROBLEM_PRODUCTS from "@/graphql/queries/oneCProblemProducts.graphql";
import NO_BARCODE_QUERY from "@/graphql/queries/quarantineNoBarcode.graphql";
import SHOPS_LIST from "@/graphql/queries/shops_list.graphql";
import SetBarcodeModal from "@/views/aggregator/modals/SetBarcodeModal.vue";
import MergeDuplicatesModal from "@/views/aggregator/modals/MergeDuplicatesModal.vue";
import { useMenuStore } from "@/stores/menu.js";

// Причины качества выгрузки 1С (FOODLINK-11039), КРОМЕ штрихкода — он вынесен
// в объединённый пункт NO_BARCODE (карантин no_ean + 1С missing_barcode).
const ONE_C_LABELS = {
  missing_sku: "Без артикула",
  missing_price: "Без цены",
  missing_stock: "Без остатка",
  missing_image: "Без изображения",
  missing_name: "Без наименования",
  missing_unit: "Без единицы измерения",
};

// «Все причины» карантина агрегатора (reason = null на бэке).
const AGGREGATOR_ALL = "__all__";
// Объединённый пункт «Без штрихкода» (карантин no_ean + 1С missing_barcode).
const NO_BARCODE = "no_barcode";

export default {
  name: "AggregatorQuarantinePage",
  inject: ["apollo"],
  components: { TableComponent, IconComponent },
  data() {
    return {
      loading: false,
      // Идёт скачивание XLSX-выгрузки (FOODLINK-11194).
      exporting: false,
      first: 25,
      page: 1,
      // selected: __all__ | double_match (агрегатор) | no_barcode (union) | missing_* (1С)
      selected: AGGREGATOR_ALL,
      // Фильтр «Магазин» (FOODLINK-11191); null — все магазины.
      // Предзаполняется из deep-link предупреждения 1С (?shop_id=).
      shopId: null,
      // Список магазинов для дропдауна «Магазин».
      shops: [],
      AGGREGATOR_ALL,
      rows: {
        data: [],
        paginatorInfo: { total: 0 },
      },
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Карантин товаров");
    },
    aggregatorEnabled() {
      return useModuleConfigStore().isModuleEnabled("aggregator_enabled");
    },
    // Плоский список причин. Пункты агрегатора («Все причины», «Двойное совпадение»)
    // показываем только при включённом модуле; «Без штрихкода» и причины 1С — всегда.
    options() {
      const opts = [];
      if (this.aggregatorEnabled) {
        opts.push({ value: AGGREGATOR_ALL, label: "Все причины" });
      }
      opts.push(
        { value: "missing_sku", label: "Без артикула" },
        { value: NO_BARCODE, label: "Без штрихкода" },
        { value: "missing_price", label: "Без цены" },
        { value: "missing_stock", label: "Без остатка" },
        { value: "missing_image", label: "Без изображения" },
        { value: "missing_name", label: "Без наименования" },
        { value: "missing_unit", label: "Без единицы измерения" },
      );
      if (this.aggregatorEnabled) {
        opts.push({ value: "double_match", label: "Двойное совпадение" });
      }
      return opts;
    },
    isUnionMode() {
      return this.selected === NO_BARCODE;
    },
    isOneCMode() {
      return this.selected.startsWith("missing_");
    },
    columns() {
      if (this.isUnionMode) {
        // Штрихкод в этом режиме всегда пустой — колонку не показываем.
        return [
          { title: "Наименование", field: "name", show: true },
          { title: "Артикул", field: "sku", show: true },
          { title: "Магазин", field: "shop", show: true },
          { title: "Дата", field: "created_at", show: true },
          { width: "220px", field: "action", system: true, show: true },
        ];
      }
      if (this.isOneCMode) {
        return [
          { title: "Наименование", field: "name", show: true },
          { title: "Артикул", field: "sku", show: true },
          { title: "Штрихкод", field: "barcode", show: true },
          { title: "Магазин", field: "shop", show: true },
          { title: "Дата", field: "created_at", show: true },
        ];
      }
      return [
        { title: "Что пришло", field: "incoming_title", show: true },
        { title: "Магазин", field: "shop", show: true },
        { title: "Причина", field: "reason", show: true },
        { title: "Дата", field: "created_at", show: true },
        { width: "220px", field: "action", system: true, show: true },
      ];
    },
  },
  mounted() {
    const shopId = parseInt(this.$route.query.shop_id, 10);
    this.shopId = Number.isNaN(shopId) ? null : shopId;

    const code = this.$route.query.code;
    if (code === "missing_barcode") {
      // Предупреждение 1С «без штрихкода» открывает объединённый пункт.
      this.selected = NO_BARCODE;
    } else if (code && Object.prototype.hasOwnProperty.call(ONE_C_LABELS, code)) {
      this.selected = code;
    } else if (!this.aggregatorEnabled) {
      // Агрегатор выключен (страница доступна только по deep-link из 1С) —
      // дефолтимся на первую причину 1С.
      this.selected = Object.keys(ONE_C_LABELS)[0];
    } else {
      this.selected = AGGREGATOR_ALL;
    }

    this.get();
    this.fetchShops();
  },
  methods: {
    // Скачать текущую выборку (причина + магазин) в XLSX (FOODLINK-11194).
    async exportToExcel() {
      if (this.exporting) return;
      this.exporting = true;
      try {
        const params = new URLSearchParams({ code: this.selected });
        if (this.shopId !== null) params.append("shop_id", this.shopId);

        const response = await fetch(
          `${import.meta.env.VITE_HOST_ENDPOINT}/action/export/quarantine?${params}`,
          {
            headers: {
              Authorization: `Bearer ${Cookies.get("admin_token")}`,
              // Без Accept: application/json бэкенд на 401 уйдёт в redirect на route('login').
              Accept: "application/json",
            },
          }
        );
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const blob = await response.blob();
        const disposition = response.headers.get("content-disposition") || "";
        const match = disposition.match(/filename=([^;]+)/);
        const filename = match ? match[1].trim() : "quarantine.xlsx";

        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
      } catch (e) {
        console.error("Quarantine export failed:", e);
      } finally {
        this.exporting = false;
      }
    },
    fetchShops() {
      this.apollo()
        .admin.query({ query: SHOPS_LIST })
        .then(({ data }) => {
          this.shops = data.shops || [];
        })
        .catch(() => {
          this.shops = [];
        });
    },
    formatDate(value) {
      return value ? dayjs(value).format("DD.MM.YYYY HH:mm") : "—";
    },
    reasonLabel(reason) {
      return reason === "no_ean" ? "Нет штрихкода" : "Двойное совпадение";
    },
    // Ключ поля карточки, которое подсвечивается красным (FOODLINK-11458).
    // Нормализует причину карантина в имя поля товара курьерки.
    highlightKey(row) {
      // Карантин агрегатора (no_ean / double_match) — проблема в штрихкоде.
      if (row.reason === "no_ean" || row.reason === "double_match") return "barcode";
      // union «Без штрихкода» (source quarantine | onec) — тоже штрихкод.
      if (row.source) return "barcode";
      // Режим качества выгрузки 1С: причина в row.code либо в выбранном фильтре.
      const code = row.code || this.selected;
      const map = {
        missing_sku: "sku",
        missing_name: "name",
        missing_price: "price",
        missing_stock: "stock",
        missing_image: "image",
        missing_unit: "unit",
        missing_barcode: "barcode",
      };
      return map[code] || null;
    },
    // Ссылка на карточку товара курьерки с подсветкой проблемного поля.
    // null — товара в курьерке нет (название тогда не кликабельно).
    productLink(row) {
      if (!row.local_product_id) return null;
      const key = this.highlightKey(row);
      return {
        name: "product",
        params: { shop_id: row.shop_id, id: row.local_product_id },
        query: key ? { quarantine_highlight: key } : {},
      };
    },
    onFilter() {
      this.page = 1;
      // Чистим строки, чтобы не было кадра со старыми данными под новыми колонками.
      this.rows.data = [];
      this.get();
    },
    handleFirstCount(count) {
      this.first = count;
      this.page = 1;
      this.get();
    },
    handlePaginate(page) {
      this.page = page;
      this.get();
    },
    get() {
      if (this.loading) return;
      this.loading = true;

      let query;
      let root;
      let variables;
      if (this.isUnionMode) {
        query = NO_BARCODE_QUERY;
        root = "quarantineNoBarcode";
        variables = { first: this.first, page: this.page, shop_id: this.shopId };
      } else if (this.isOneCMode) {
        query = PROBLEM_PRODUCTS;
        root = "oneCProblemProducts";
        variables = { first: this.first, page: this.page, shop_id: this.shopId, code: this.selected };
      } else {
        query = QUARANTINE;
        root = "aggregatorQuarantine";
        variables = {
          first: this.first,
          page: this.page,
          reason: this.selected === AGGREGATOR_ALL ? null : this.selected,
          resolved: false,
          shop_id: this.shopId,
        };
      }

      this.apollo()
        .admin.query({
          query,
          variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data[root]) {
            this.rows.data = data[root].data;
            this.rows.paginatorInfo = data[root].paginatorInfo;
          }
          this.loading = false;
        })
        .catch(() => {
          this.loading = false;
        });
    },
    openSetBarcode(row) {
      useDefaultStore().modals.push({
        component: shallowRef(SetBarcodeModal),
        options: {
          quarantineId: row.id,
          // union-строки отдают название в поле name (alias incoming_title).
          incomingTitle: row.incoming_title || row.name,
          callback: this.get,
        },
      });
    },
    openMerge(row) {
      let matchedIds = [];
      try {
        matchedIds = row.matched_platform_product_ids ? JSON.parse(row.matched_platform_product_ids) : [];
      } catch (e) {
        matchedIds = [];
      }
      useDefaultStore().modals.push({
        component: shallowRef(MergeDuplicatesModal),
        options: {
          candidateIds: matchedIds,
          quarantineId: row.id,
          callback: this.get,
        },
      });
    },
  },
};
</script>

<style lang="stylus">
.quarantine-page {
  &__header {
    display flex
    align-items center
    justify-content space-between
    gap 16px
    margin-bottom 16px
  }

  &__filters {
    display flex
    align-items center
    gap 8px
  }

  &__filter {
    padding 8px 12px
    border 1px solid var(--gray-light)
    border-radius 8px
    max-width 280px
  }

  &__actions {
    display flex
    gap 8px
  }

  &__product-link {
    color var(--main)
    text-decoration none
    cursor pointer

    &:hover {
      text-decoration underline
    }
  }
}
</style>
