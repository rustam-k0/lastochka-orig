<template>
  <form class="merchant-promocode-form" @submit.prevent="submit">
    <div class="merchant-promocode-form__grid">
      <!-- Platform-wide (только для админа) -->
      <div v-if="isAdmin" class="merchant-promocode-form__field merchant-promocode-form__field--toggle">
        <div>
          <label class="merchant-promocode-form__label">Для всей платформы</label>
          <span class="merchant-promocode-form__hint">Промокод действует на товары всех магазинов</span>
        </div>
        <label class="merchant-promocode-form__toggle">
          <input
            type="checkbox"
            :checked="form.scope === 'platform'"
            :disabled="isEdit"
            @change="onScopeToggle($event)"
          />
          <span class="merchant-promocode-form__toggle-slider"></span>
          <span class="merchant-promocode-form__toggle-text">
            {{ form.scope === 'platform' ? 'Включён' : 'Выключен' }}
          </span>
        </label>
      </div>

      <!-- Мерчант (только для админа) -->
      <div v-if="showShopSelect" class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Магазин <span class="required">*</span></label>
        <ShopSelectComponent
          v-model="form.shop_id"
          :errors="errors.shop_id ? [errors.shop_id] : []"
          :disabled="isEdit"
        />
      </div>

      <!-- Показ названия магазина для селлера (readonly) -->
      <div v-else-if="sellerShopName" class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Магазин</label>
        <div class="merchant-promocode-form__readonly">{{ sellerShopName }}</div>
      </div>

      <!-- Название -->
      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Название <span class="required">*</span></label>
        <input 
          type="text"
          v-model="form.title"
          placeholder="Например: Новогодняя акция"
          class="merchant-promocode-form__input"
          :class="{ 'merchant-promocode-form__input--error': errors.title }"
          maxlength="255"
        />
        <span v-if="errors.title" class="merchant-promocode-form__error">{{ errors.title }}</span>
      </div>

      <!-- Код промокода -->
      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Код промокода <span class="required">*</span></label>
        <input 
          type="text"
          v-model="form.code"
          placeholder="NEWYEAR2025"
          class="merchant-promocode-form__input"
          :class="{ 'merchant-promocode-form__input--error': errors.code }"
          maxlength="50"
          @input="onCodeInput"
        />
        <span class="merchant-promocode-form__hint">Код, который клиент вводит при оформлении заказа</span>
        <span v-if="errors.code" class="merchant-promocode-form__error">{{ errors.code }}</span>
      </div>

      <!-- Тип скидки -->
      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Тип скидки <span class="required">*</span></label>
        <div class="merchant-promocode-form__radio-group">
          <label class="merchant-promocode-form__radio">
            <input type="radio" v-model="form.discount_type" value="percent" />
            <span>Процент</span>
          </label>
          <label class="merchant-promocode-form__radio">
            <input type="radio" v-model="form.discount_type" value="fixed" />
            <span>Фиксированная сумма</span>
          </label>
        </div>
      </div>

      <!-- Значение скидки -->
      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">
          {{ form.discount_type === 'percent' ? 'Процент скидки' : 'Сумма скидки' }}
          <span class="required">*</span>
        </label>
        <div class="merchant-promocode-form__number-wrapper">
          <input
            type="number"
            v-model.number="form.discount_value"
            :placeholder="form.discount_type === 'percent' ? '15' : '100'"
            class="merchant-promocode-form__input"
            :class="{ 'merchant-promocode-form__input--error': errors.discount_value }"
            min="1"
            :max="form.discount_type === 'percent' ? 100 : undefined"
          />
          <span class="merchant-promocode-form__percent">{{ form.discount_type === 'percent' ? '%' : '₽' }}</span>
        </div>
        <span v-if="errors.discount_value" class="merchant-promocode-form__error">{{ errors.discount_value }}</span>
      </div>

      <!-- Период действия -->
      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Период действия <span class="required">*</span></label>
        <div class="merchant-promocode-form__date-range">
          <DateInput
            v-model="form.valid_from"
            placeholder="Дата начала"
            :errors="errors.valid_from ? [errors.valid_from] : []"
          />
          <span class="merchant-promocode-form__date-separator">—</span>
          <DateInput
            v-model="form.valid_to"
            placeholder="Дата окончания"
            :errors="errors.valid_to ? [errors.valid_to] : []"
          />
        </div>
      </div>

      <!-- Ограничения использования -->
      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Макс. количество использований (всего)</label>
        <input
          type="number"
          v-model.number="form.max_uses"
          placeholder="без ограничений"
          class="merchant-promocode-form__input"
          :class="{ 'merchant-promocode-form__input--error': errors.max_uses }"
          min="1"
        />
        <span v-if="errors.max_uses" class="merchant-promocode-form__error">{{ errors.max_uses }}</span>
      </div>

      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Макс. использований на клиента</label>
        <input
          type="number"
          v-model.number="form.max_uses_per_client"
          placeholder="без ограничений"
          class="merchant-promocode-form__input"
          :class="{ 'merchant-promocode-form__input--error': errors.max_uses_per_client }"
          min="1"
        />
        <span class="merchant-promocode-form__hint">Например, 1 — промокод на первый заказ</span>
        <span v-if="errors.max_uses_per_client" class="merchant-promocode-form__error">{{ errors.max_uses_per_client }}</span>
      </div>

      <!-- Привязка к товарам/категориям -->
      <div class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Применяется к</label>
        <div class="merchant-promocode-form__radio-group">
          <label class="merchant-promocode-form__radio">
            <input type="radio" v-model="form.binding_type" value="all" />
            <span>Все товары</span>
          </label>
          <label class="merchant-promocode-form__radio">
            <input
              type="radio"
              v-model="form.binding_type"
              value="products"
              :disabled="form.scope === 'platform'"
            />
            <span>Конкретные товары</span>
          </label>
          <label class="merchant-promocode-form__radio">
            <input
              type="radio"
              v-model="form.binding_type"
              value="categories"
              :disabled="form.scope === 'platform'"
            />
            <span>Конкретные категории</span>
          </label>
        </div>
        <span v-if="form.scope === 'platform'" class="merchant-promocode-form__hint">
          Platform-wide промокоды применяются только ко всем товарам
        </span>
      </div>

      <!-- Селектор товаров -->
      <div v-if="form.binding_type === 'products'" class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Товары <span class="required">*</span></label>
        <SelectComponent
          title=""
          placeholder="Поиск товаров..."
          :model-value="form.product_ids"
          @update:model-value="form.product_ids = $event"
          :options="productOptions"
          label-name="title"
          :multiple="true"
          :loading="productsLoading"
          :errors="errors.product_ids ? [errors.product_ids] : []"
          @update:search="onProductSearch"
        />
      </div>

      <!-- Селектор категорий -->
      <div v-if="form.binding_type === 'categories'" class="merchant-promocode-form__field">
        <label class="merchant-promocode-form__label">Категории <span class="required">*</span></label>
        <CategoryTreeSelect
          title=""
          placeholder="Выберите категории..."
          :model-value="form.category_ids"
          @update:model-value="form.category_ids = $event"
          :options="categoryOptions"
          :multiple="true"
          :leaf-only="true"
          :errors="errors.category_ids ? [errors.category_ids] : []"
        />
      </div>

      <!-- Активность -->
      <div class="merchant-promocode-form__field merchant-promocode-form__field--toggle">
        <label class="merchant-promocode-form__label">Активность</label>
        <label class="merchant-promocode-form__toggle">
          <input type="checkbox" v-model="form.is_active" />
          <span class="merchant-promocode-form__toggle-slider"></span>
          <span class="merchant-promocode-form__toggle-text">{{ form.is_active ? 'Активен' : 'Неактивен' }}</span>
        </label>
      </div>
    </div>

    <!-- Кнопки -->
    <div class="merchant-promocode-form__actions">
      <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">
        Отмена
      </button>
      <button type="submit" class="btn btn--md" :disabled="loading">
        <span v-if="loading">Сохранение...</span>
        <span v-else>{{ isEdit ? 'Сохранить' : 'Создать' }}</span>
      </button>
    </div>
  </form>
</template>

<script>
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import DateInput from "@/components/inputs/DateInput.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CategoryTreeSelect from "@/components/inputs/CategoryTreeSelect.vue";
import SHOPS_PAGINATE from "@/graphql/queries/shops_paginate.graphql";
import SHOP_PRODUCTS from "@/graphql/queries/shop_products_for_promocode.graphql";
import SHOP_CATEGORIES from "@/graphql/queries/shop_categories_for_promocode.graphql";
import GLOBAL_CATEGORIES from "@/graphql/queries/global_categories_for_promocode.graphql";
import { useAuthStore } from "@/stores/auth.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import dayjs from "dayjs";

export default {
  name: "MerchantPromocodeForm",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    DateInput,
    SelectComponent,
    CategoryTreeSelect,
  },
  props: {
    initialData: {
      type: Object,
      default: null,
    },
    loading: {
      type: Boolean,
      default: false,
    },
    isEdit: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["submit", "cancel"],
  data() {
    return {
      form: {
        title: "",
        code: "",
        scope: "store",
        shop_id: null,
        discount_type: "percent",
        discount_value: null,
        valid_from: null,
        valid_to: null,
        is_active: true,
        max_uses: null,
        max_uses_per_client: null,
        binding_type: "all",
        product_ids: [],
        category_ids: [],
      },
      errors: {},
      shops: [],
      sellerShopName: null,
      productOptions: [],
      productsLoading: false,
      productSearchTimeout: null,
      categoryOptions: [],
    };
  },
  computed: {
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles?.[0]?.name;
    },
    isAdmin() {
      return this.userRole === "admin";
    },
    isSeller() {
      return this.userRole === "shop";
    },
    showShopSelect() {
      return this.isAdmin && this.form.scope !== "platform";
    },
  },
  watch: {
    initialData: {
      immediate: true,
      handler(data) {
        if (data) {
          const shopId = data.shop_id || data.shop?.id || null;
          const scope = (data.scope || "store").toLowerCase();
          this.form = {
            title: data.title || "",
            code: data.code || "",
            scope,
            shop_id: shopId ? parseInt(shopId) : null,
            discount_type: data.discount_type || "percent",
            discount_value: data.discount_value || data.discount_percent || null,
            valid_from: data.valid_from ? dayjs(data.valid_from).format() : null,
            valid_to: data.valid_to ? dayjs(data.valid_to).format() : null,
            is_active: data.is_active ?? true,
            max_uses: data.max_uses ?? null,
            max_uses_per_client: data.max_uses_per_client ?? null,
            binding_type: data.binding_type || "all",
            product_ids: (data.products || []).map(p => ({ id: parseInt(p.id), title: p.title })),
            category_ids: (data.categories || []).map(c => ({ id: parseInt(c.id), title: c.title })),
          };
          if (data.shop?.title) {
            this.sellerShopName = data.shop.title;
          }
          if (shopId) {
            if (this.form.binding_type === 'products') this.fetchProducts(shopId);
            if (this.form.binding_type === 'categories') this.fetchCategories(shopId);
          }
        }
      },
    },
    "form.valid_from"(newVal) {
      if (newVal && this.form.valid_to && dayjs(this.form.valid_to).isBefore(dayjs(newVal))) {
        this.form.valid_to = null;
      }
    },
    "form.shop_id"(newVal) {
      if (newVal) {
        if (this.form.binding_type === 'products') this.fetchProducts(newVal);
        if (this.form.binding_type === 'categories') this.fetchCategories(newVal);
      }
    },
    "form.binding_type"(newVal) {
      const shopId = this.form.shop_id;
      if (!shopId) return;
      if (newVal === 'products') this.fetchProducts(shopId);
      if (newVal === 'categories') this.fetchCategories(shopId);
    },
  },
  mounted() {
    this.fetchShops();
  },
  methods: {
    fetchShops() {
      this.apollo()
        .admin.query({
          query: SHOPS_PAGINATE,
          variables: { first: 100 },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.shops = data.shops_paginate?.data || [];
          
          if (this.isSeller && this.shops.length === 1 && !this.form.shop_id) {
            this.form.shop_id = parseInt(this.shops[0].id);
            this.sellerShopName = this.shops[0].title;
          } else if (this.isSeller && this.shops.length > 0 && !this.sellerShopName) {
            const currentShop = this.shops.find(s => parseInt(s.id) === this.form.shop_id);
            if (currentShop) {
              this.sellerShopName = currentShop.title;
            }
          }
        })
        .catch((error) => {
          console.error("Error fetching shops:", error);
        });
    },
    onCodeInput(event) {
      this.form.code = event.target.value.toUpperCase();
    },
    validate() {
      this.errors = {};
      if (!this.form.title || !this.form.title.trim()) {
        this.errors.title = "Название обязательно";
      } else if (this.form.title.length > 255) {
        this.errors.title = "Максимум 255 символов";
      }
      if (!this.form.code || !this.form.code.trim()) {
        this.errors.code = "Код обязателен";
      } else if (this.form.code.length > 50) {
        this.errors.code = "Максимум 50 символов";
      }
      if (this.isAdmin && this.form.scope !== "platform" && !this.form.shop_id) {
        this.errors.shop_id = "Выберите магазин";
      }
      if (!this.form.discount_value) {
        this.errors.discount_value = this.form.discount_type === 'percent'
          ? "Укажите процент скидки" : "Укажите сумму скидки";
      } else if (this.form.discount_type === 'percent' && (this.form.discount_value < 1 || this.form.discount_value > 100)) {
        this.errors.discount_value = "Скидка должна быть от 1% до 100%";
      } else if (this.form.discount_value < 1) {
        this.errors.discount_value = "Значение должно быть не менее 1";
      }
      if (!this.form.valid_from) {
        this.errors.valid_from = "Укажите дату начала";
      }
      if (!this.form.valid_to) {
        this.errors.valid_to = "Укажите дату окончания";
      } else if (this.form.valid_from && dayjs(this.form.valid_to).isBefore(dayjs(this.form.valid_from))) {
        this.errors.valid_to = "Дата окончания должна быть после даты начала";
      }
      if (this.form.binding_type === 'products' && (!this.form.product_ids || this.form.product_ids.length === 0)) {
        this.errors.product_ids = "Выберите хотя бы один товар";
      }
      if (this.form.binding_type === 'categories' && (!this.form.category_ids || this.form.category_ids.length === 0)) {
        this.errors.category_ids = "Выберите хотя бы одну категорию";
      }
      if (this.form.max_uses !== null && this.form.max_uses !== '' && this.form.max_uses < 1) {
        this.errors.max_uses = "Значение должно быть не менее 1";
      }
      if (this.form.max_uses_per_client !== null && this.form.max_uses_per_client !== '' && this.form.max_uses_per_client < 1) {
        this.errors.max_uses_per_client = "Значение должно быть не менее 1";
      }
      if (
        this.form.max_uses !== null && this.form.max_uses !== '' &&
        this.form.max_uses_per_client !== null && this.form.max_uses_per_client !== '' &&
        this.form.max_uses_per_client > this.form.max_uses
      ) {
        this.errors.max_uses_per_client = "Лимит на клиента не может превышать общий лимит";
      }
      return Object.keys(this.errors).length === 0;
    },
    submit() {
      if (!this.validate()) return;
      const data = {
        title: this.form.title.trim(),
        code: this.form.code.trim().toUpperCase(),
        discount_type: this.form.discount_type.toUpperCase(),
        discount_value: parseInt(this.form.discount_value),
        valid_from: dayjs(this.form.valid_from).format("YYYY-MM-DD"),
        valid_to: dayjs(this.form.valid_to).format("YYYY-MM-DD"),
        is_active: this.form.is_active,
        max_uses: this.form.max_uses === null || this.form.max_uses === '' ? null : parseInt(this.form.max_uses),
        max_uses_per_client: this.form.max_uses_per_client === null || this.form.max_uses_per_client === '' ? null : parseInt(this.form.max_uses_per_client),
        binding_type: this.form.binding_type.toUpperCase(),
      };
      if (this.form.binding_type === 'products') {
        data.product_ids = this.form.product_ids.map(p => typeof p === 'object' ? parseInt(p.id) : parseInt(p));
      }
      if (this.form.binding_type === 'categories') {
        data.category_ids = this.form.category_ids.map(c => typeof c === 'object' ? parseInt(c.id) : parseInt(c));
      }
      if (!this.isEdit) {
        data.scope = this.form.scope.toUpperCase();
        if (this.form.scope !== "platform") {
          data.shop_id = parseInt(this.form.shop_id);
        }
      }
      this.$emit("submit", data);
    },
    onScopeToggle(event) {
      if (event.target.checked) {
        this.form.scope = "platform";
        this.form.shop_id = null;
        this.form.binding_type = "all";
        this.productOptions = [];
        this.categoryOptions = [];
        this.form.product_ids = [];
        this.form.category_ids = [];
      } else {
        this.form.scope = "store";
      }
    },
    fetchProducts(shopId, search = "") {
      this.productsLoading = true;
      this.apollo()
        .admin.query({
          query: SHOP_PRODUCTS,
          variables: { shop_id: parseInt(shopId), search, first: 50 },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.productOptions = (data.shopProductsForSale?.data || []).map(p => ({
            id: parseInt(p.id),
            title: p.title,
          }));
        })
        .catch((error) => console.error("Error fetching products:", error))
        .finally(() => { this.productsLoading = false; });
    },
    fetchCategories(shopId) {
      // Сетевой каталог: категории общие для сети, а pivot shops_categories у
      // якорного магазина пуст — shop_categories(shop_id) вернул бы пустой
      // список (MID-240). Берём глобальное дерево, как карточка товара.
      if (useModuleConfigStore().isModuleEnabled("network_catalog")) {
        this.apollo()
          .admin.query({
            query: GLOBAL_CATEGORIES,
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            this.categoryOptions = data.parent_categories || [];
          })
          .catch((error) => console.error("Error fetching categories:", error));
        return;
      }

      this.apollo()
        .admin.query({
          query: SHOP_CATEGORIES,
          variables: { shop_id: parseInt(shopId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.categoryOptions = data.shop_categories || [];
        })
        .catch((error) => console.error("Error fetching categories:", error));
    },
    onProductSearch(search) {
      clearTimeout(this.productSearchTimeout);
      this.productSearchTimeout = setTimeout(() => {
        const shopId = this.form.shop_id;
        if (shopId) this.fetchProducts(shopId, search);
      }, 300);
    },
  },
};
</script>

<style lang="stylus">
.merchant-promocode-form {
  &__grid {
    display flex
    flex-direction column
    gap 24px
    max-width 600px
  }
  
  &__field {
    &--toggle {
      display flex
      align-items center
      justify-content space-between
    }
  }
  
  &__label {
    display block
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    margin-bottom 8px
    
    .required {
      color var(--red)
    }
  }
  
  &__input,
  &__select {
    width 100%
    padding 12px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem
    outline none
    transition border-color 0.2s
    background var(--white)
    
    &:focus {
      border-color var(--pink)
    }
    
    &--error {
      border-color var(--red)
    }
    
    &:disabled {
      background var(--gray-3)
      cursor not-allowed
    }
  }
  
  &__readonly {
    padding 12px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem
    background var(--gray-3)
    color var(--dark)
  }
  
  &__select-wrapper {
    position relative
  }
  
  &__select {
    appearance none
    padding-right 40px
    cursor pointer
  }
  
  &__select-icon {
    position absolute
    right 12px
    top 50%
    transform translateY(-50%)
    pointer-events none
    
    svg {
      width 20px
      height 20px
    }
  }
  
  &__radio-group {
    display flex
    gap 20px
  }

  &__radio {
    display flex
    align-items center
    gap 8px
    cursor pointer
    font-size 0.875rem

    input[type="radio"] {
      accent-color var(--pink)
      width 16px
      height 16px
    }
  }

  &__number-wrapper {
    position relative
    max-width 200px
  }
  
  &__percent {
    position absolute
    right 16px
    top 50%
    transform translateY(-50%)
    color var(--dark-light)
    font-size 0.875rem
  }
  
  &__date-range {
    display flex
    align-items center
    gap 12px
    
    .date-input {
      flex 1
    }
  }
  
  &__date-separator {
    color var(--dark-light)
    flex-shrink 0
  }
  
  &__hint {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--dark-light)
  }
  
  &__error {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--red)
  }
  
  &__toggle {
    display flex
    align-items center
    gap 12px
    cursor pointer
    
    input {
      display none
    }
  }
  
  &__toggle-slider {
    width 48px
    height 24px
    background var(--gray-dark)
    border-radius 24px
    position relative
    transition background 0.2s
    
    &::after {
      content ''
      position absolute
      width 20px
      height 20px
      background var(--white)
      border-radius 50%
      top 2px
      left 2px
      transition left 0.2s
    }
    
    input:checked + & {
      background var(--green)
      
      &::after {
        left 26px
      }
    }
  }
  
  &__toggle-text {
    font-size 0.875rem
    color var(--dark)
  }
  
  &__actions {
    display flex
    justify-content flex-end
    gap 16px
    margin-top 32px
    padding-top 24px
    border-top 1px solid var(--gray-dark)
  }
}
</style>
