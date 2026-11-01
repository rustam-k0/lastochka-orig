<template>
  <div class="default-modal bulk-assign-supplements-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Назначить добавку ({{ count }} товар{{ countSuffix }})</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <div class="default-modal__body bulk-assign-supplements-modal__body">
      <InputComponent
        placeholder="Название добавки"
        title="Название"
        v-model="form.title.value"
        :errors="form.title.errors"
        required
      />
      <SelectComponent
        title="Тип выбора"
        placeholder="Выберите тип"
        label-name="value"
        :options="typeOptions"
        v-model="form.type.value"
        :errors="form.type.errors"
        clearable
      />
      <CheckboxComponent v-model="form.is_required.value">
        <span>Сделать добавку обязательной</span>
      </CheckboxComponent>

      <div class="bulk-assign-supplements-modal__section-title">Варианты добавки</div>
      <ArrayComponent
        ref="supplementValues"
        :template="valuesTemplate"
        v-model="form.values"
      >
        <template v-slot="{ item }">
          <div class="bulk-assign-supplements-modal__value">
            <div class="bulk-assign-supplements-modal__value-type">
              <label class="bulk-assign-supplements-modal__value-type-label">
                <input type="radio" value="manual" v-model="item.value_type" />
                Ручная
              </label>
              <label class="bulk-assign-supplements-modal__value-type-label">
                <input type="radio" value="catalog" v-model="item.value_type" />
                Из каталога
              </label>
            </div>
            <div v-if="item.value_type === 'catalog'" class="bulk-assign-supplements-modal__catalog">
              <div class="bulk-assign-supplements-modal__catalog-search">
                <CategoryTreeSelect
                  class="bulk-assign-supplements-modal__catalog-category"
                  placeholder="Фильтр по категории"
                  v-model="item.productSearchCategory"
                  :options="categoriesTree"
                  leaf-only
                  clearable
                  @update:model-value="() => { if (item.productSearch && item.productSearch.length >= 2) searchProduct(item, item.productSearch); }"
                />
                <InputComponent
                  placeholder="Поиск товара..."
                  :model-value="item.productSearch"
                  @update:model-value="(v) => { item.productSearch = v; searchProduct(item, v); }"
                />
                <div v-if="item.productSearchResults && item.productSearchResults.length" class="bulk-assign-supplements-modal__catalog-results">
                  <div
                    v-for="p in item.productSearchResults"
                    :key="p.id"
                    class="bulk-assign-supplements-modal__catalog-result-item"
                    @click="selectProduct(item, p)"
                  >
                    {{ p.title }}
                  </div>
                </div>
                <div v-if="item.product" class="bulk-assign-supplements-modal__catalog-selected">
                  Выбрано: <strong>{{ item.product.title }}</strong>
                  <button type="button" class="bulk-assign-supplements-modal__catalog-clear" @click="clearProduct(item)">✕</button>
                </div>
              </div>
            </div>
            <InputComponent
              placeholder="Название варианта"
              v-model="item.title.value"
              :errors="item.title.errors"
            />
            <InputComponent
              :placeholder="item.value_type === 'catalog' ? 'Цена (необязательно, пусто = цена товара)' : 'Цена'"
              type="number"
              v-model="item.price.value"
              :errors="item.price.errors"
            />
            <InputComponent
              placeholder="Макс. количество"
              type="number"
              v-model="item.max_quantity.value"
              :errors="item.max_quantity.errors"
            />
            <InputComponent
              v-if="item.value_type === 'catalog'"
              placeholder="Позиция"
              title="Позиция"
              type="number"
              v-model="item.position.value"
              :errors="item.position.errors"
            />
            <SelectComponent
              v-if="item.value_type === 'catalog'"
              title="Учёт остатка"
              placeholder="По умолчанию (наследовать)"
              :options="trackStockOptions"
              v-model="item.track_stock.value"
              :errors="item.track_stock.errors"
              clearable
            />
          </div>
        </template>
      </ArrayComponent>
    </div>
    <div class="default-modal__footer bulk-assign-supplements-modal__footer">
      <button type="button" @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button type="button" @click="submit" :disabled="loading" class="btn btn--main btn--md">
        {{ loading ? "Назначение…" : "Назначить" }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import ArrayComponent from "@/components/inputs/ArrayComponent.vue";
import CategoryTreeSelect from "@/components/inputs/CategoryTreeSelect.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import BULK_ASSIGN from "@/graphql/mutations/BulkAssignSupplements.graphql";
import PRODUCTS_SEARCH from "@/graphql/queries/admin_products_search.graphql";
import OPTIONS from "@/graphql/pages/Product/options.graphql";

export default {
  name: "BulkAssignSupplementsModal",
  inject: ["apollo"],
  components: {
    IconComponent,
    InputComponent,
    SelectComponent,
    CheckboxComponent,
    ArrayComponent,
    CategoryTreeSelect,
  },
  emits: ["close", "success"],
  props: {
    productIds: {
      type: Array,
      default: () => [],
    },
    shopId: {
      type: Number,
      default: null,
    },
    callback: {
      type: Function,
      default: null,
    },
  },
  mounted() {
    this.apollo()
      .admin.query({
        query: OPTIONS,
        variables: { shop_id: this.shopId ?? undefined },
      })
      .then(({ data }) => {
        this.categoriesTree = data.shop_categories ?? [];
      });
  },
  data() {
    return {
      loading: false,
      categoriesTree: [],
      typeOptions: [
        { id: 1, title: "one", value: "Выбор одного" },
        { id: 2, title: "many", value: "Выбор многих" },
      ],
      trackStockOptions: [
        { id: null, title: "По умолчанию (наследовать)" },
        { id: true, title: "Учитывать остаток" },
        { id: false, title: "Не учитывать" },
      ],
      valuesTemplate: {
        value_type: "manual",
        title: { value: null, errors: [] },
        price: { value: null, errors: [] },
        product_id: null,
        product: null,
        productSearch: "",
        productSearchCategory: null,
        productSearchResults: [],
        productSearchLoading: false,
        max_quantity: { value: null, errors: [] },
        track_stock: { value: null, errors: [] },
        position: { value: null, errors: [] },
      },
      form: {
        title: { value: null, errors: [] },
        type: { value: null, errors: [] },
        is_required: { value: false, errors: [] },
        values: [
          {
            value_type: "manual",
            title: { value: null, errors: [] },
            price: { value: null, errors: [] },
            product_id: null,
            product: null,
            productSearch: "",
            productSearchResults: [],
            productSearchLoading: false,
            max_quantity: { value: null, errors: [] },
            track_stock: { value: null, errors: [] },
            position: { value: null, errors: [] },
          },
        ],
      },
    };
  },
  computed: {
    count() {
      return this.productIds.length;
    },
    countSuffix() {
      const n = this.count % 100;
      const n1 = this.count % 10;
      if (n >= 11 && n <= 19) return "ов";
      if (n1 === 1) return "";
      if (n1 >= 2 && n1 <= 4) return "а";
      return "ов";
    },
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    searchProduct(val, query) {
      if (val._searchDebounce) clearTimeout(val._searchDebounce);
      if (!query || query.length < 2) {
        val.productSearchResults = [];
        return;
      }
      val._searchDebounce = setTimeout(() => {
        val.productSearchLoading = true;
        const categories = val.productSearchCategory ? [val.productSearchCategory.id] : undefined;
        this.apollo()
          .admin.query({
            query: PRODUCTS_SEARCH,
            variables: { title: query, first: 10, categories },
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            val.productSearchResults = data.admin_products_paginate?.data || [];
          })
          .catch(() => {
            val.productSearchResults = [];
          })
          .finally(() => {
            val.productSearchLoading = false;
          });
      }, 300);
    },
    selectProduct(val, product) {
      val.product_id = parseInt(product.id);
      val.product = { id: parseInt(product.id), title: product.title };
      if (!val.title.value) {
        val.title.value = product.title;
      }
      if (!val.price.value && product.default_price?.value != null) {
        val.price.value = product.default_price.value;
      }
      val.productSearch = "";
      val.productSearchResults = [];
    },
    clearProduct(val) {
      val.product_id = null;
      val.product = null;
      val.productSearch = "";
      val.productSearchResults = [];
    },
    submit() {
      this.resetErrors();
      // Фикс 2: явный сброс ошибок вариантов (form.values — массив, стандартный resetErrors его не обходит)
      this.form.values.forEach((val) => {
        ["title", "price", "max_quantity", "track_stock"].forEach((field) => {
          if (val[field]) val[field].errors = [];
        });
      });

      if (!this.form.title.value) {
        this.form.title.errors = ["Укажите название добавки"];
        return;
      }
      if (!this.form.values.length) {
        return;
      }

      const supplement = {
        title: this.form.title.value,
        is_required: this.form.is_required.value ?? false,
        type: this.form.type.value?.title || undefined,
        values: this.form.values.map((val) => {
          const isCatalog = val.value_type === "catalog";
          return {
            title: val.title?.value || undefined,
            price: val.price?.value != null && val.price?.value !== ""
              ? parseInt(val.price.value)
              : undefined,
            product_id: isCatalog && val.product_id ? val.product_id : undefined,
            max_quantity: val.max_quantity?.value != null && val.max_quantity?.value !== ""
              ? parseInt(val.max_quantity.value)
              : undefined,
            track_stock: isCatalog && val.track_stock?.value != null
              ? val.track_stock.value.id
              : undefined,
            position: isCatalog && val.position?.value != null && val.position?.value !== ""
              ? parseInt(val.position.value)
              : undefined,
          };
        }),
      };

      this.loading = true;
      this.apollo()
        .admin.mutate({
          mutation: BULK_ASSIGN,
          variables: {
            product_ids: this.productIds.map((id) => parseInt(id)),
            supplement,
          },
        })
        .then(() => {
          this.$emit("success");
          if (this.callback) {
            this.callback();
          }
          this.$emit("close");
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            // Фикс 1: кастомный маппинг ошибок вида supplement.* → form.*
            // Laravel возвращает ключи с префиксом "supplement.", например:
            //   supplement.title → form.title
            //   supplement.values.0.title → form.values[0].title
            graphQLErrors.forEach((err) => {
              if (err.extensions?.category === "validation") {
                const validation = {};
                Object.keys(err.extensions.validation).forEach((key) => {
                  // Снимаем префикс "supplement." если он есть
                  const mapped = key.startsWith("supplement.") ? key.slice("supplement.".length) : key;
                  validation[mapped] = err.extensions.validation[key];
                });
                // Применяем маппинг к форме вручную
                Object.keys(validation).forEach((key) => {
                  const arr = key.split(".");
                  if (arr.length <= 1) {
                    if (this.form[key]) {
                      this.form[key].errors = validation[key];
                    }
                  } else {
                    const [parent, index, child] = arr;
                    if (parent === "values" && this.form.values[index] && child && this.form.values[index][child]) {
                      this.form.values[index][child].errors = [...validation[key]];
                    }
                  }
                });
              }
            });
          }
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.bulk-assign-supplements-modal {
  width 560px

  &__body {
    display flex
    flex-direction column
    gap 16px
    max-height 70vh
    overflow-y auto
  }

  &__section-title {
    font-weight 600
    font-size 14px
    color #374151
    margin-top 4px
  }

  &__value {
    display flex
    flex-direction column
    gap 10px
    padding 12px
    background #F9FAFB
    border-radius 8px
    border 1px solid #E5E7EB
  }

  &__value-type {
    display flex
    gap 16px
    align-items center
  }

  &__value-type-label {
    display flex
    align-items center
    gap 6px
    font-size 13px
    cursor pointer
    color #374151
  }

  &__catalog {
    padding 8px 0
  }

  &__catalog-search {
    position relative
  }

  &__catalog-category {
    margin-bottom 8px
  }

  &__catalog-results {
    position absolute
    top 100%
    left 0
    right 0
    z-index 10
    background #fff
    border 1px solid #E5E7EB
    border-radius 6px
    box-shadow 0 4px 12px rgba(0,0,0,0.1)
    max-height 200px
    overflow-y auto
  }

  &__catalog-result-item {
    padding 8px 12px
    cursor pointer
    font-size 13px
    &:hover {
      background #F3F4F6
    }
  }

  &__catalog-selected {
    margin-top 6px
    font-size 13px
    color #374151
    display flex
    align-items center
    gap 8px
  }

  &__catalog-clear {
    background none
    border none
    cursor pointer
    color #6B7280
    font-size 14px
    padding 0 4px
    &:hover {
      color #EF4444
    }
  }

  &__footer {
    display flex
    gap 16px

    .btn {
      flex 1
    }
  }
}
</style>
