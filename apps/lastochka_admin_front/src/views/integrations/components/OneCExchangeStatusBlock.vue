<template>
  <div class="onec-status">
    <!-- URL обмена -->
    <div class="onec-status__url" v-if="exchangeUrl">
      <label class="onec-status__label">URL обмена (для настройки в 1С)</label>
      <div class="onec-status__url-row">
        <input
          type="text"
          :value="exchangeUrl"
          readonly
          class="onec-status__url-input"
          ref="urlInput"
        />
        <button class="onec-status__copy-btn" @click="copyUrl" :title="copied ? 'Скопировано!' : 'Копировать'">
          {{ copied ? '✓' : '📋' }}
        </button>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="onec-status__loading">
      Загрузка статуса обмена...
    </div>

    <!-- Статус по типам данных -->
    <div v-else-if="dataTypes.length" class="onec-status__section">
      <label class="onec-status__label">Статус обмена</label>

      <div
        v-for="dt in dataTypes"
        :key="dt.data_type"
        class="onec-status__type"
        :class="'onec-status__type--' + dt.status_label"
      >
        <div class="onec-status__type-header">
          <span class="onec-status__type-icon">{{ statusIcon(dt.status_label) }}</span>
          <span class="onec-status__type-name">{{ typeName(dt.data_type) }}</span>
          <span class="onec-status__type-date" v-if="dt.last_exchange_at">
            {{ formatDate(dt.last_exchange_at) }}
          </span>
          <span class="onec-status__type-date onec-status__type-date--empty" v-else>
            Не получено
          </span>
        </div>

        <!-- Counters -->
        <div class="onec-status__counters" v-if="dt.last_exchange_at && dt.counters">
          <span v-for="(value, key) in parseJson(dt.counters)" :key="key" class="onec-status__counter">
            {{ counterLabel(key) }}: {{ value }}
          </span>
        </div>

        <!-- Stale warning -->
        <div class="onec-status__stale" v-if="dt.status_label === 'stale'">
          Не обновлялось {{ hoursAgo(dt.last_exchange_at) }}ч (порог: {{ dt.stale_threshold_hours }}ч)
        </div>

        <!-- Error -->
        <div class="onec-status__error-text" v-if="dt.status_label === 'error' && dt.error">
          {{ dt.error }}
        </div>

        <!-- Warnings -->
        <div class="onec-status__warnings" v-if="dt.warnings">
          <div
            v-for="(w, i) in parseJson(dt.warnings)"
            :key="i"
            class="onec-status__warning"
            :class="{ 'onec-status__warning--link': isClickableWarning(w) }"
            @click="isClickableWarning(w) && openProblemProducts(w)"
          >
            {{ w.message || w }}
            <span v-if="isClickableWarning(w)" class="onec-status__warning-arrow">→</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Ошибка загрузки -->
    <div v-else-if="loadError" class="onec-status__error-text">
      {{ loadError }}
    </div>

    <!-- Пустой статус -->
    <div v-else class="onec-status__empty">
      Обмен ещё не выполнялся
    </div>

    <div class="onec-status__hint">
      Типы данных обновляются независимо — в 1С может быть настроено несколько узлов обмена
    </div>
  </div>
</template>

<script>
import ONEC_EXCHANGE_STATUS from "@/graphql/queries/oneCExchangeStatus.graphql";
import { useDefaultStore } from "@/stores/default.js";

// Коды предупреждений, для которых есть список конкретных товаров на странице
// «Проблемные товары 1С» (FOODLINK-11039). missing_warehouse_id — про склады,
// не про товар, поэтому не кликабелен.
const LISTABLE_CODES = [
  "missing_name",
  "missing_sku",
  "missing_barcode",
  "missing_unit",
  "missing_image",
  "missing_price",
  "missing_stock",
];

const TYPE_NAMES = {
  catalog_import: "Каталог товаров",
  offers_import: "Цены и остатки",
  rests_import: "Остатки",
  orders_import: "Заказы (импорт статусов от 1С)",
  orders_export: "Заказы (экспорт в 1С)",
};

const COUNTER_LABELS = {
  categories_count: "Категорий",
  products_count: "Товаров",
  images_count: "Изображений",
  skipped_whitelist: "Пропущено (whitelist)",
  prices_count: "Цен",
  stocks_count: "Остатков",
  skipped_count: "Пропущено",
  processed_count: "Обработано",
  exported_count: "Экспортировано",
  pending_count: "В очереди",
};

export default {
  name: "OneCExchangeStatusBlock",
  inject: ["apollo"],
  props: {
    shopId: {
      type: Number,
      default: null,
    },
  },
  data() {
    return {
      exchangeUrl: "",
      dataTypes: [],
      loading: true,
      copied: false,
      loadError: null,
    };
  },
  mounted() {
    if (this.shopId) {
      this.loadStatus();
    }
  },
  methods: {
    loadStatus() {
      this.loading = true;
      this.loadError = null;
      this.apollo()
        .admin.query({
          query: ONEC_EXCHANGE_STATUS,
          variables: { shop_id: this.shopId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.exchangeUrl = data.oneCExchangeStatus.exchange_url || "";
          this.dataTypes = data.oneCExchangeStatus.data_types || [];
        })
        .catch((error) => {
          console.error("Error loading 1C exchange status:", error);
          this.loadError = error.message || "Ошибка загрузки статуса";
        })
        .finally(() => {
          this.loading = false;
        });
    },
    copyUrl() {
      if (this.$refs.urlInput) {
        navigator.clipboard.writeText(this.exchangeUrl).then(() => {
          this.copied = true;
          setTimeout(() => { this.copied = false; }, 2000);
        });
      }
    },
    typeName(type) {
      return TYPE_NAMES[type] || type;
    },
    counterLabel(key) {
      return COUNTER_LABELS[key] || key;
    },
    statusIcon(label) {
      const icons = {
        ok: "✅",
        warnings: "⚠️",
        stale: "⏰",
        error: "❌",
        no_data: "—",
      };
      return icons[label] || "—";
    },
    parseJson(val) {
      if (!val) return null;
      if (typeof val === "object") return val;
      try { return JSON.parse(val); } catch { return null; }
    },
    formatDate(dateString) {
      if (!dateString) return "";
      const date = new Date(dateString);
      return date.toLocaleString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    },
    hoursAgo(dateString) {
      if (!dateString) return 0;
      const diff = Date.now() - new Date(dateString).getTime();
      return Math.floor(diff / (1000 * 60 * 60));
    },
    isClickableWarning(w) {
      return !!(this.shopId && w && w.code && LISTABLE_CODES.includes(w.code));
    },
    openProblemProducts(w) {
      // Закрываем модалку интеграции, иначе она останется поверх новой страницы
      useDefaultStore().modals = [];
      this.$router.push({
        name: "onec-problem-products",
        query: { shop_id: this.shopId, code: w.code },
      });
    },
  },
};
</script>

<style lang="stylus">
.onec-status
  display flex
  flex-direction column
  gap 16px

  &__label
    font-weight 400
    font-size 0.813rem
    line-height 18px
    color var(--dark)

  &__url-row
    display flex
    gap 8px

  &__url-input
    flex 1
    height 40px
    padding 0 12px
    border 1px solid var(--border-color)
    border-radius 5px
    background var(--gray-3)
    font-size 0.813rem
    color var(--dark)
    cursor text
    user-select all

  &__copy-btn
    width 40px
    height 40px
    border 1px solid var(--border-color)
    border-radius 5px
    background var(--white)
    cursor pointer
    display flex
    align-items center
    justify-content center
    font-size 1rem
    transition background 0.2s

    &:hover
      background var(--gray-3)

  &__section
    display flex
    flex-direction column
    gap 8px

  &__type
    padding 12px 16px
    border-radius 8px
    border 1px solid var(--gray-dark)
    background var(--white)

    &--ok
      border-left 3px solid var(--green)

    &--warnings
      border-left 3px solid #f0a000

    &--stale
      border-left 3px solid #f0a000

    &--error
      border-left 3px solid var(--red)

    &--no_data
      border-left 3px solid var(--gray-dark)
      opacity 0.6

  &__type-header
    display flex
    align-items center
    gap 8px

  &__type-icon
    font-size 0.875rem

  &__type-name
    font-size 0.813rem
    font-weight 500
    color var(--dark)

  &__type-date
    margin-left auto
    font-size 0.75rem
    color var(--dark-light, #888)

    &--empty
      font-style italic

  &__counters
    display flex
    flex-wrap wrap
    gap 8px 16px
    margin-top 6px
    font-size 0.75rem
    color var(--dark-light, #888)

  &__counter
    white-space nowrap

  &__stale
    margin-top 6px
    font-size 0.75rem
    color #f0a000

  &__error-text
    margin-top 6px
    font-size 0.75rem
    color var(--red)

  &__warnings
    margin-top 6px
    display flex
    flex-direction column
    gap 4px

  &__warning
    font-size 0.75rem
    color #f0a000

    &--link
      cursor pointer
      text-decoration underline
      transition opacity 0.15s

      &:hover
        opacity 0.7

  &__warning-arrow
    margin-left 4px
    text-decoration none

  &__empty
    font-size 0.875rem
    color var(--dark-light, #888)
    padding 12px 16px
    background var(--gray-3)
    border-radius 8px
    text-align center

  &__hint
    font-size 0.75rem
    color var(--dark-light, #888)
    font-style italic

  &__loading
    font-size 0.875rem
    color var(--dark-light, #888)
    text-align center
    padding 12px
</style>
