<template>
  <div class="default-modal add-stop-list-product">
    <div class="default-modal__header">
      <span class="default-modal__title">Добавить товар в стоп-лист</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <div class="default-modal__body add-stop-list-product__body">
      <InputComponent
        placeholder="Поиск по названию или коду товара"
        title="Товар"
        :model-value="search"
        @update:model-value="onSearch"
      />

      <div class="add-stop-list-product__results">
        <div v-if="loading" class="add-stop-list-product__state">Загрузка…</div>
        <div
          v-else-if="!search || search.length < 2"
          class="add-stop-list-product__state"
        >
          Введите минимум 2 символа для поиска
        </div>
        <div v-else-if="!results.length" class="add-stop-list-product__state">
          Ничего не найдено
        </div>
        <template v-else>
          <div
            v-for="product in results"
            :key="product.id"
            class="add-stop-list-product__item"
          >
            <div class="add-stop-list-product__item-info">
              <span class="add-stop-list-product__item-articul">
                {{ product.articul || "—" }}
              </span>
              <span class="add-stop-list-product__item-title">{{ product.title }}</span>
            </div>
            <button
              v-if="isInList(product.id)"
              type="button"
              class="btn btn--white btn--sm"
              disabled
            >
              В списке
            </button>
            <button
              v-else
              type="button"
              class="btn btn--md add-stop-list-product__add-btn"
              :disabled="adding"
              @click="add(product)"
            >
              <IconComponent name="add-circle-line" />
              <span>Добавить</span>
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SEARCH from "@/graphql/pages/Stores/stop_list_product_search.graphql";
import ADD from "@/graphql/pages/Stores/ProductStopListAdd.graphql";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "AddStopListProduct",
  inject: ["apollo"],
  components: { IconComponent, InputComponent },
  emits: ["close"],
  props: {
    shopId: { type: Number, required: true },
    // «hidden» | «pickup_only»
    type: { type: String, required: true },
    // id товаров, уже добавленных в этот стоп-лист (для пометки «В списке»)
    existingIds: { type: Array, default: () => [] },
    callback: { type: Function, default: null },
  },
  data() {
    return {
      search: "",
      results: [],
      loading: false,
      adding: false,
      addedIds: [],
      debounce: null,
    };
  },
  computed: {
    // Нормализуем к числам: id из shop_stop_list_products и admin_products_paginate
    // могут сериализоваться по-разному (Int/String) — сравнение по числу устойчивее.
    existingIdSet() {
      return new Set(this.existingIds.map((id) => parseInt(id)));
    },
  },
  methods: {
    isInList(id) {
      const num = parseInt(id);
      return this.existingIdSet.has(num) || this.addedIds.includes(num);
    },
    onSearch(value) {
      this.search = value;
      if (this.debounce) clearTimeout(this.debounce);
      if (!value || value.length < 2) {
        this.results = [];
        this.loading = false;
        return;
      }
      this.loading = true;
      this.debounce = setTimeout(() => {
        this.apollo()
          .admin.query({
            query: SEARCH,
            variables: {
              shop_id: this.shopId,
              title: value,
              first: 20,
              page: 1,
            },
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            this.results = data.admin_products_paginate?.data || [];
          })
          .catch(() => {
            this.results = [];
          })
          .finally(() => {
            this.loading = false;
          });
      }, 300);
    },
    add(product) {
      if (this.adding) return;
      this.adding = true;
      this.apollo()
        .admin.mutate({
          mutation: ADD,
          variables: {
            product_id: parseInt(product.id),
            type: this.type,
          },
        })
        .then(() => {
          this.addedIds.push(parseInt(product.id));
          if (this.callback) this.callback();
          useDefaultStore().modals = [];
        })
        .catch(() => {
          alert("Не удалось добавить товар в стоп-лист");
        })
        .finally(() => {
          this.adding = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.add-stop-list-product {
  width 100%
  max-width 560px

  &__body {
    gap 16px
  }

  &__results {
    display flex
    flex-direction column
    width 100%
    max-height 60vh
    overflow-y auto
    border 1px solid var(--gray-light)
    border-radius 8px
  }

  &__state {
    padding 20px
    text-align center
    color var(--dark-light)
    font-size 0.875rem
  }

  &__item {
    display flex
    align-items center
    justify-content space-between
    gap 12px
    padding 12px 16px
    border-bottom 1px solid var(--white-2)

    &:last-child {
      border-bottom none
    }
  }

  &__item-info {
    display flex
    flex-direction column
    gap 2px
    min-width 0
  }

  &__item-articul {
    font-size 0.75rem
    color var(--dark-light)
  }

  &__item-title {
    font-size 0.875rem
    color var(--dark)
    overflow hidden
    text-overflow ellipsis
  }

  &__add-btn {
    flex-shrink 0
  }
}
</style>
