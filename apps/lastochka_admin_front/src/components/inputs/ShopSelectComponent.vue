<template>
  <SelectComponent
    class="shop-select"
    :title="title"
    :placeholder="placeholder"
    label-name="title"
    :model-value="selected"
    :options="options"
    :loading="loading"
    :errors="errors"
    :required="required"
    :clearable="clearable"
    :disabled="disabled"
    :small="small"
    @update:model-value="select"
    @update:search="onSearch"
  />
</template>

<script>
import SelectComponent from "@/components/inputs/select/input.vue";
import ADMIN_SHOPS_SEARCH from "@/graphql/queries/admin_shops_search.graphql";

// Сколько магазинов отдаём в выпадающий список за раз: в сети их ~1.5k,
// бэкенд отдаёт не больше 100 на страницу, остальные находятся поиском (MID-310)
const PAGE_SIZE = 100;

/**
 * Выбор одного магазина с поиском на сервере.
 * v-model — id магазина (Number) или null. Выбранный магазин подгружается
 * по id, даже если не попал в первую страницу списка.
 * Событие change приходит после update:modelValue с тем же id.
 */
export default {
  name: "ShopSelectComponent",
  components: { SelectComponent },
  inject: ["apollo"],
  emits: ["update:modelValue", "change"],
  props: {
    modelValue: {
      type: [Number, String],
      default: null,
    },
    title: {
      type: String,
      default: "",
    },
    placeholder: {
      type: String,
      default: "Выберите магазин",
    },
    errors: {
      type: Array,
      default: () => [],
    },
    required: Boolean,
    clearable: Boolean,
    disabled: Boolean,
    small: Boolean,
  },
  data() {
    return {
      options: [],
      selected: null,
      picked: null,
      loading: false,
      searchDebounce: null,
      searchSeq: 0,
    };
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(id) {
        this.syncSelected(id);
      },
    },
  },
  mounted() {
    this.fetchOptions();
  },
  beforeUnmount() {
    clearTimeout(this.searchDebounce);
  },
  methods: {
    query(variables) {
      return this.apollo()
        .admin.query({
          query: ADMIN_SHOPS_SEARCH,
          variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => data.shops_paginate?.data || []);
    },
    fetchOptions(title = null) {
      const seq = ++this.searchSeq;
      this.loading = true;
      this.query({ first: PAGE_SIZE, title: title || undefined })
        .then((shops) => {
          // Ответ устаревшего поиска не должен перетирать свежий
          if (seq === this.searchSeq) {
            this.options = shops;
          }
        })
        .catch((error) => {
          console.error("Error fetching shops:", error);
        })
        .finally(() => {
          if (seq === this.searchSeq) {
            this.loading = false;
          }
        });
    },
    onSearch(title) {
      clearTimeout(this.searchDebounce);
      this.searchDebounce = setTimeout(() => this.fetchOptions(title), 300);
    },
    syncSelected(id) {
      if (id === null || id === undefined || id === "") {
        this.selected = null;
        return;
      }
      const shopId = parseInt(id);
      if (this.selected && parseInt(this.selected.id) === shopId) {
        return;
      }
      const known = [this.picked, ...this.options].find((shop) => shop && parseInt(shop.id) === shopId);
      if (known) {
        this.selected = known;
        return;
      }
      this.query({ ids: [shopId], first: 1 })
        .then(([shop]) => {
          if (parseInt(this.modelValue) === shopId) {
            this.selected = shop || null;
          }
        })
        .catch((error) => {
          console.error("Error fetching shop:", error);
        });
    },
    // Отображаемое значение меняет только родитель через modelValue: так он
    // может отклонить выбор (например, до подтверждения смены магазина)
    select(shop) {
      this.picked = shop;
      const id = shop ? parseInt(shop.id) : null;
      this.$emit("update:modelValue", id);
      this.$emit("change", id);
    },
  },
};
</script>

<style lang="stylus">
// В строке фильтров списка контейнер сжимается по содержимому, а названия магазинов длинные
.shop-select {
  min-width 240px
}
</style>
