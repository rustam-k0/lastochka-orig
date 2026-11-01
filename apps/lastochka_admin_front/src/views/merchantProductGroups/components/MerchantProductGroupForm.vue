<template>
  <form class="merchant-product-group-form" @submit.prevent="submit">
    <div class="merchant-product-group-form__grid">
      <!-- Левая колонка - основные поля -->
      <div class="merchant-product-group-form__main">
        <!-- Название -->
        <div class="merchant-product-group-form__field">
          <label class="merchant-product-group-form__label">Название <span class="required">*</span></label>
          <input 
            type="text"
            v-model="form.title"
            placeholder="Введите название группы"
            class="merchant-product-group-form__input"
            :class="{ 'merchant-product-group-form__input--error': errors.title }"
            maxlength="255"
          />
          <span v-if="errors.title" class="merchant-product-group-form__error">{{ errors.title }}</span>
        </div>

        <!-- Описание -->
        <div class="merchant-product-group-form__field">
          <label class="merchant-product-group-form__label">Описание</label>
          <textarea
            v-model="form.description"
            placeholder="Описание проекта"
            class="merchant-product-group-form__textarea"
            rows="4"
          ></textarea>
        </div>

        <!-- Магазины: в сетевом каталоге при создании — мультивыбор или вся
             сеть (MID-229); иначе прежний одиночный селект -->
        <div v-if="isAdmin && isNetworkCreate" class="merchant-product-group-form__field">
          <ShopMultiTargetField
            v-model="form.multi_target"
            :options="shops"
            :error="errors.shop_id"
            @search="onShopSearch"
          />
        </div>
        <div v-else-if="isAdmin" class="merchant-product-group-form__field">
          <label class="merchant-product-group-form__label">Магазин <span class="required">*</span></label>
          <ShopSelectComponent
            :model-value="form.shop_id"
            :errors="errors.shop_id ? [errors.shop_id] : []"
            @change="onShopChange"
          />
        </div>

        <!-- Комплексное решение -->
        <div
          v-if="isComplexSolutionModuleEnabled"
          class="merchant-product-group-form__field merchant-product-group-form__field--toggle"
        >
          <label class="merchant-product-group-form__label">Комплексное решение</label>
          <label class="merchant-product-group-form__toggle">
            <input type="checkbox" v-model="form.is_complex_solution" />
            <span class="merchant-product-group-form__toggle-slider"></span>
            <span class="merchant-product-group-form__toggle-text">{{
              form.is_complex_solution ? "Да" : "Нет"
            }}</span>
          </label>
        </div>

        <!-- Места размещения (недоступны для комплексных решений) -->
        <template v-if="!isComplexSolution">
          <PlacementCheckboxes
            v-model:showOnMain="form.show_on_main"
            v-model:showOnMerchantPage="form.show_on_merchant_page"
            v-model:showInMerchantCatalog="form.show_in_merchant_catalog"
            v-model:showOnSearch="form.show_on_search"
            v-model:isRecommended="form.is_recommended"
            v-model:isCartPage="form.is_cart_page"
            :isAdmin="isAdmin"
          />

          <CustomCheckboxesField entity="MerchantProductGroup" v-model="form.custom_checkboxes" />
        </template>

        <!-- Фильтры комплексных решений (только для комплексных решений) -->
        <FilterValuePicker v-if="isComplexSolution" v-model="form.filter_value_ids" />

        <!-- Порядок сортировки -->
        <div class="merchant-product-group-form__field">
          <label class="merchant-product-group-form__label">Порядок сортировки</label>
          <input 
            type="number"
            v-model.number="form.sort_order"
            placeholder="0"
            class="merchant-product-group-form__input merchant-product-group-form__input--small"
            min="0"
          />
        </div>

        <!-- Активность -->
        <div class="merchant-product-group-form__field merchant-product-group-form__field--toggle">
          <label class="merchant-product-group-form__label">Активность</label>
          <label class="merchant-product-group-form__toggle">
            <input type="checkbox" v-model="form.is_active" />
            <span class="merchant-product-group-form__toggle-slider"></span>
            <span class="merchant-product-group-form__toggle-text">{{ form.is_active ? 'Активна' : 'Неактивна' }}</span>
          </label>
        </div>

      </div>

      <!-- Правая колонка - изображение и товары -->
      <div class="merchant-product-group-form__side">
        <!-- Изображение -->
        <div class="merchant-product-group-form__field">
          <label class="merchant-product-group-form__label">Изображение группы</label>
          <div class="merchant-product-group-form__image-upload">
            <ImageUpload
              v-model="form.image"
              text="Загрузить изображение"
              @uploadImage="onImageUpload"
            />
          </div>
        </div>

        <!-- Способ наполнения: вручную или автоматическая подборка -->
        <div class="merchant-product-group-form__field">
          <label class="merchant-product-group-form__label">Способ наполнения</label>
          <div class="merchant-product-group-form__select-wrapper">
            <select
              v-model="form.fill_type"
              class="merchant-product-group-form__select"
              :class="{
                'merchant-product-group-form__select--error': errors.fill_type,
              }"
            >
              <option v-for="option in fillTypeOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <IconComponent name="arrow-drop-down-line" class="merchant-product-group-form__select-icon" />
          </div>
          <span v-if="errors.fill_type" class="merchant-product-group-form__error">{{
            errors.fill_type
          }}</span>
          <span v-else-if="isAutoFill" class="merchant-product-group-form__hint">
            Товары подбираются автоматически, выбирать их вручную не нужно
          </span>
        </div>

        <!-- Товары -->
        <ProductGroupProductPicker
          v-if="!isAutoFill"
          v-model="form.products"
          :shopId="pickerShopId"
          :error="errors.products"
          :showQuantity="isQuantityEnabled"
        />
      </div>
    </div>

    <!-- Кнопки -->
    <div class="merchant-product-group-form__actions">
      <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">
        Отмена
      </button>
      <button type="submit" class="btn btn--md" :disabled="loading">
        <span v-if="loading">Сохранение...</span>
        <span v-else>{{ isEdit ? 'Сохранить' : 'Создать' }}</span>
      </button>
    </div>

    <!-- Модальное окно подтверждения смены мерчанта -->
    <div v-if="showShopChangeConfirm" class="merchant-product-group-form__modal-overlay" @click.self="cancelShopChange">
      <div class="merchant-product-group-form__modal">
        <h3>Сменить магазин?</h3>
        <p>При смене магазина выбранные товары будут сброшены. Продолжить?</p>
        <div class="merchant-product-group-form__modal-actions">
          <button type="button" class="btn btn--white btn--sm" @click="cancelShopChange">Отмена</button>
          <button type="button" class="btn btn--sm" @click="confirmShopChange">Продолжить</button>
        </div>
      </div>
    </div>
  </form>
</template>

<script>
import ImageUpload from "@/components/ImageUpload.vue";
import IconComponent from "@/components/IconComponent.vue";
import PlacementCheckboxes from "./PlacementCheckboxes.vue";
import ProductGroupProductPicker from "./ProductGroupProductPicker.vue";
import FilterValuePicker from "./FilterValuePicker.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import ShopMultiTargetField from "@/components/inputs/ShopMultiTargetField.vue";
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import SHOPS_PAGINATE from "@/graphql/queries/shops_paginate.graphql";
import { useAuthStore } from "@/stores/auth.js";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { parseCustomCheckboxes } from "@/utils.js";
import { FILL_TYPE_MANUAL, FILL_TYPE_OPTIONS, isAutoFillType } from "../fillTypes.js";

// Сентинел shop_id для пикера товаров в сетевом каталоге: бэкенд в network
// mode игнорирует shop_id и отдаёт весь каталог сети (MID-229)
const NETWORK_PICKER_SHOP_ID = -1;

export default {
  name: "MerchantProductGroupForm",
  inject: ["apollo"],
  components: {
    ImageUpload,
    IconComponent,
    PlacementCheckboxes,
    ProductGroupProductPicker,
    FilterValuePicker,
    CustomCheckboxesField,
    ShopMultiTargetField,
    ShopSelectComponent,
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
        description: "",
        shop_id: null,
        multi_target: { all_shops: false, shops: [] },
        show_on_main: false,
        show_on_merchant_page: true,
        show_in_merchant_catalog: false,
        show_on_search: false,
        is_recommended: false,
        is_cart_page: false,
        is_complex_solution: false,
        sort_order: 0,
        is_active: true,
        image: null,
        products: [],
        filter_value_ids: [],
        custom_checkboxes: {},
        fill_type: FILL_TYPE_MANUAL,
      },
      fillTypeOptions: FILL_TYPE_OPTIONS,
      errors: {},
      shops: [],
      shopSearchDebounce: null,
      showShopChangeConfirm: false,
      pendingShopId: null,
      previousShopId: null,
    };
  },
  computed: {
    networkCatalogEnabled() {
      return useModuleConfigStore().isModuleEnabled("network_catalog");
    },
    // Мультивыбор магазинов — только при создании в сетевом каталоге;
    // редактирование остаётся пер-запись (MID-229)
    isNetworkCreate() {
      return this.networkCatalogEnabled && !this.isEdit;
    },
    pickerShopId() {
      return this.isNetworkCreate ? NETWORK_PICKER_SHOP_ID : this.form.shop_id;
    },
    checkboxStore() {
      return useCheckboxConfigStore();
    },
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles?.[0]?.name;
    },
    isAdmin() {
      return this.userRole === "admin";
    },
    isQuantityEnabled() {
      return useModuleConfigStore().isModuleEnabled("product_group_quantity");
    },
    isComplexSolutionModuleEnabled() {
      return useModuleConfigStore().isModuleEnabled("product_group_filters");
    },
    isComplexSolution() {
      return this.isComplexSolutionModuleEnabled && this.form.is_complex_solution;
    },
    isAutoFill() {
      return isAutoFillType(this.form.fill_type);
    },
    userShopId() {
      return this.user?.shop_id || this.user?.shop?.id;
    },
  },
  watch: {
    initialData: {
      immediate: true,
      handler(data) {
        if (data) {
          const shopId = data.shop_id || data.shop?.id || null;
          this.form = {
            title: data.title || "",
            description: data.description || "",
            shop_id: shopId ? parseInt(shopId) : null,
            show_on_main: data.show_on_main ?? false,
            show_on_merchant_page: data.show_on_merchant_page ?? true,
            show_in_merchant_catalog: data.show_in_merchant_catalog ?? false,
            show_on_search: data.show_on_search ?? false,
            is_recommended: data.is_recommended ?? false,
            is_cart_page: data.is_cart_page ?? false,
            is_complex_solution: data.is_complex_solution ?? false,
            sort_order: data.sort_order ?? 0,
            is_active: data.is_active ?? true,
            image: data.image || null,
            products: (data.products || []).map(p => ({ ...p, id: parseInt(p.id), quantity: p.pivot_quantity || 1 })),
            filter_value_ids: (data.filterValues || []).map(v => parseInt(v.id)),
            custom_checkboxes: parseCustomCheckboxes(data.custom_checkboxes),
            fill_type: data.fill_type || FILL_TYPE_MANUAL,
          };
          this.previousShopId = shopId ? parseInt(shopId) : null;
        } else if (!this.isAdmin && this.userShopId) {
          this.form.shop_id = parseInt(this.userShopId);
          this.previousShopId = parseInt(this.userShopId);
        }
      },
    },
  },
  mounted() {
    if (this.isAdmin) {
      this.fetchShops();
    } else if (this.userShopId && !this.form.shop_id) {
      this.form.shop_id = parseInt(this.userShopId);
      this.previousShopId = parseInt(this.userShopId);
    }
  },
  methods: {
    fetchShops(title = null) {
      this.apollo()
        .admin.query({
          query: SHOPS_PAGINATE,
          variables: { first: 100, title: title || undefined },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.shops = data.shops_paginate?.data || [];
        })
        .catch((error) => {
          console.error("Error fetching shops:", error);
        });
    },
    // Серверный поиск магазинов для мультиселекта: в сети магазинов ~1.5k,
    // first:100 без поиска не покрывает список (MID-229)
    onShopSearch(title) {
      clearTimeout(this.shopSearchDebounce);
      this.shopSearchDebounce = setTimeout(() => this.fetchShops(title), 300);
    },
    onShopChange(newShopId) {

      if (this.form.products.length > 0 && this.previousShopId && this.previousShopId !== newShopId) {
        this.pendingShopId = newShopId;
        this.form.shop_id = this.previousShopId;
        this.showShopChangeConfirm = true;
      } else {
        this.previousShopId = newShopId;
        this.form.shop_id = newShopId;
      }
    },
    confirmShopChange() {
      this.form.shop_id = this.pendingShopId;
      this.form.products = [];
      this.previousShopId = this.pendingShopId;
      this.pendingShopId = null;
      this.showShopChangeConfirm = false;
    },
    cancelShopChange() {
      this.pendingShopId = null;
      this.showShopChangeConfirm = false;
    },
    validate() {
      this.errors = {};
      
      if (!FILL_TYPE_OPTIONS.some((o) => o.value === this.form.fill_type)) {
        this.errors.fill_type = "Выберите способ наполнения";
      }

      if (!this.form.title || !this.form.title.trim()) {
        this.errors.title = "Название обязательно";
      } else if (this.form.title.length > 255) {
        this.errors.title = "Максимум 255 символов";
      }
      
      if (this.isAdmin && this.isNetworkCreate) {
        if (!this.form.multi_target.all_shops && !this.form.multi_target.shops.length) {
          this.errors.shop_id = "Выберите магазины или включите «Во всех магазинах сети»";
        }
      } else if (this.isAdmin && !this.form.shop_id) {
        this.errors.shop_id = "Выберите магазин";
      }

      return Object.keys(this.errors).length === 0;
    },
    submit() {
      if (!this.validate()) return;

      // Комплексное решение и места размещения взаимоисключающие
      const isComplexSolution = this.isComplexSolution;
      const isAutoFill = this.isAutoFill;

      const data = {
        title: this.form.title.trim(),
        show_on_main: isComplexSolution ? false : this.form.show_on_main,
        show_on_merchant_page: isComplexSolution ? false : this.form.show_on_merchant_page,
        show_in_merchant_catalog: isComplexSolution ? false : this.form.show_in_merchant_catalog,
        show_on_search: isComplexSolution ? false : this.form.show_on_search,
        is_recommended: isComplexSolution ? false : this.form.is_recommended,
        is_cart_page: isComplexSolution ? false : this.form.is_cart_page,
        is_complex_solution: this.isComplexSolutionModuleEnabled ? isComplexSolution : undefined,
        sort_order: this.form.sort_order ? parseInt(this.form.sort_order) : 0,
        is_active: this.form.is_active,
        fill_type: this.form.fill_type || FILL_TYPE_MANUAL,
        // Автоматическая группа товары не хранит: состав считает витрина
        product_ids:
          this.isQuantityEnabled || isAutoFill ? undefined : this.form.products.map((p) => parseInt(p.id)),
        products_with_qty:
          this.isQuantityEnabled && !isAutoFill
            ? this.form.products.map((p) => ({
                product_id: parseInt(p.id),
                quantity: parseInt(p.quantity) || 1,
              }))
            : undefined,
        filter_value_ids: this.isComplexSolutionModuleEnabled
          ? isComplexSolution
            ? this.form.filter_value_ids.map((id) => parseInt(id))
            : []
          : undefined,
        custom_checkboxes: JSON.stringify(this.form.custom_checkboxes || {}),
      };

      if (this.isAdmin && this.isNetworkCreate) {
        if (this.form.multi_target.all_shops) {
          data.all_shops = true;
        } else {
          data.shop_ids = this.form.multi_target.shops.map((s) => parseInt(s.id));
        }
      } else if (this.form.shop_id) {
        data.shop_id = parseInt(this.form.shop_id);
      }

      if (this.form.image && this.form.image.path) {
        data.image = {
          path: this.form.image.path,
          origin_name: this.form.image.origin_name || "image.jpg",
          alt: this.form.image.alt || this.form.title,
          size: String(this.form.image.size || "0"),
          type: this.form.image.type || "image/jpeg",
        };
      }

      this.$emit("submit", data);
    },
    onImageUpload() {
      // Callback after image upload
    },
  },
};
</script>

<style lang="stylus">
.merchant-product-group-form {
  &__grid {
    display grid
    grid-template-columns 1fr 1fr
    gap 32px
    
    +below(1200px) {
      grid-template-columns 1fr
    }
  }
  
  &__main,
  &__side {
    display flex
    flex-direction column
    gap 24px
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
  &__select,
  &__textarea {
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

    &--small {
      width 120px
    }
  }

  &__textarea {
    resize vertical
    font-family inherit
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
  
  &__error {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--red)
  }

  &__hint {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--dark-light)
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
  
  &__image-upload {
    width 100%
    max-width 300px
    
    .image-upload__container {
      width 100%
      height 180px
    }
  }
  
  &__actions {
    display flex
    justify-content flex-end
    gap 16px
    margin-top 32px
    padding-top 24px
    border-top 1px solid var(--gray-dark)
  }
  
  &__modal-overlay {
    position fixed
    top 0
    left 0
    right 0
    bottom 0
    background rgba(0, 0, 0, 0.5)
    display flex
    align-items center
    justify-content center
    z-index 1000
  }
  
  &__modal {
    background var(--white)
    border-radius 12px
    padding 24px
    max-width 400px
    width 90%
    
    h3 {
      font-size 1.125rem
      font-weight 600
      color var(--dark)
      margin-bottom 12px
    }
    
    p {
      font-size 0.875rem
      color var(--dark-light)
      margin-bottom 24px
    }
  }
  
  &__modal-actions {
    display flex
    justify-content flex-end
    gap 12px
  }
}
</style>
