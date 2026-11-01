<template>
  <form class="merchant-banner-form" @submit.prevent="submit">
    <div class="merchant-banner-form__grid">
      <!-- Левая колонка - основные поля -->
      <div class="merchant-banner-form__main">
        <!-- Название -->
        <div class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Название <span class="required">*</span></label>
          <input 
            type="text"
            v-model="form.title"
            placeholder="Введите название баннера"
            class="merchant-banner-form__input"
            :class="{ 'merchant-banner-form__input--error': errors.title }"
            maxlength="255"
          />
          <span v-if="errors.title" class="merchant-banner-form__error">{{ errors.title }}</span>
        </div>

        <!-- Магазины: в сетевом каталоге при создании — мультивыбор или вся
             сеть (MID-238); иначе прежний одиночный селект -->
        <div v-if="isAdmin && isNetworkCreate" class="merchant-banner-form__field">
          <ShopMultiTargetField
            v-model="form.multi_target"
            :options="shops"
            :error="errors.shop_id"
            @search="onShopSearch"
          />
        </div>
        <div v-else-if="isAdmin" class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Магазин <span class="required">*</span></label>
          <ShopSelectComponent
            v-model="form.shop_id"
            :errors="errors.shop_id ? [errors.shop_id] : []"
            :disabled="isEdit"
            @change="onShopChange"
          />
        </div>

        <!-- Место показа -->
        <div class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Место показа</label>
          <div class="merchant-banner-form__select-wrapper">
            <select v-model="form.display_category_id" class="merchant-banner-form__select">
              <option :value="null">Главная страница</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">
                Категория: {{ category.title }}
              </option>
            </select>
            <IconComponent name="arrow-drop-down-line" class="merchant-banner-form__select-icon" />
          </div>
          <span class="merchant-banner-form__hint">
            Где показывать баннер: в слайдере главной или на странице выбранной категории
          </span>
        </div>

        <!-- Тип перехода -->
        <div class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Тип перехода</label>
          <div class="merchant-banner-form__radio-group">
            <label class="merchant-banner-form__radio">
              <input type="radio" v-model="form.link_type" value="none" />
              <span class="merchant-banner-form__radio-mark"></span>
              <span>Без перехода</span>
            </label>
            <label class="merchant-banner-form__radio">
              <input type="radio" v-model="form.link_type" value="product" />
              <span class="merchant-banner-form__radio-mark"></span>
              <span>На товар</span>
            </label>
            <label class="merchant-banner-form__radio">
              <input type="radio" v-model="form.link_type" value="category" />
              <span class="merchant-banner-form__radio-mark"></span>
              <span>На категорию</span>
            </label>
          </div>
        </div>

        <!-- Товар (если тип = product) -->
        <div v-if="form.link_type === 'product'" class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Товар <span class="required">*</span></label>
          <div class="merchant-banner-form__select-wrapper">
            <select
              v-model="form.product_id"
              class="merchant-banner-form__select"
              :class="{ 'merchant-banner-form__select--error': errors.product_id }"
              :disabled="!isNetworkCreate && !form.shop_id && isAdmin"
            >
              <option :value="null" disabled>Выберите товар</option>
              <option v-for="product in products" :key="product.id" :value="product.id">
                {{ product.title }}
              </option>
            </select>
            <IconComponent name="arrow-drop-down-line" class="merchant-banner-form__select-icon" />
          </div>
          <span v-if="!isNetworkCreate && !form.shop_id && isAdmin" class="merchant-banner-form__hint">Сначала выберите магазин</span>
          <span v-if="errors.product_id" class="merchant-banner-form__error">{{ errors.product_id }}</span>
        </div>

        <!-- Категория (если тип = category) -->
        <div v-if="form.link_type === 'category'" class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Категория <span class="required">*</span></label>
          <div class="merchant-banner-form__select-wrapper">
            <select
              v-model="form.category_id"
              class="merchant-banner-form__select"
              :class="{ 'merchant-banner-form__select--error': errors.category_id }"
            >
              <option :value="null" disabled>Выберите категорию</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">
                {{ category.title }}
              </option>
            </select>
            <IconComponent name="arrow-drop-down-line" class="merchant-banner-form__select-icon" />
          </div>
          <span v-if="errors.category_id" class="merchant-banner-form__error">{{ errors.category_id }}</span>
        </div>

        <!-- Позиция -->
        <div class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Позиция</label>
          <input 
            type="number"
            v-model.number="form.position"
            placeholder="0"
            class="merchant-banner-form__input merchant-banner-form__input--small"
            min="0"
          />
        </div>

        <!-- Активность -->
        <div class="merchant-banner-form__field merchant-banner-form__field--toggle">
          <label class="merchant-banner-form__label">Активность</label>
          <label class="merchant-banner-form__toggle">
            <input type="checkbox" v-model="form.is_active" />
            <span class="merchant-banner-form__toggle-slider"></span>
            <span class="merchant-banner-form__toggle-text">{{ form.is_active ? 'Активен' : 'Неактивен' }}</span>
          </label>
        </div>

        <div class="merchant-banner-form__field">
          <CustomCheckboxesField entity="MerchantBanner" v-model="form.custom_checkboxes" />
        </div>
      </div>

      <!-- Правая колонка - изображение -->
      <div class="merchant-banner-form__side">
        <!-- Изображение -->
        <div class="merchant-banner-form__field">
          <label class="merchant-banner-form__label">Изображение баннера</label>
          <div class="merchant-banner-form__image-upload">
            <ImageUpload
              v-model="form.image"
              text="Загрузить изображение"
              @uploadImage="onImageUpload"
            />
          </div>
          <span class="merchant-banner-form__hint">Рекомендуемый формат: JPG, PNG, WebP. Макс. размер: 5MB</span>
        </div>
      </div>
    </div>

    <!-- Кнопки -->
    <div class="merchant-banner-form__actions">
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
import IconComponent from "@/components/IconComponent.vue";
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import ImageUpload from "@/components/ImageUpload.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import ShopMultiTargetField from "@/components/inputs/ShopMultiTargetField.vue";
import SHOPS_PAGINATE from "@/graphql/queries/shops_paginate.graphql";
import SHOP_PRODUCTS_FOR_BANNER from "@/graphql/queries/shop_products_for_banner.graphql";
import CATEGORIES from "@/graphql/queries/categories.graphql";
import { useAuthStore } from "@/stores/auth.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { parseCustomCheckboxes } from "@/utils.js";

// Сентинел shop_id для запроса товаров в сетевом каталоге: бэкенд в network
// mode игнорирует shop_id и отдаёт весь каталог сети (MID-238)
const NETWORK_PICKER_SHOP_ID = -1;

export default {
  name: "MerchantBannerForm",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    IconComponent,
    ImageUpload,
    CustomCheckboxesField,
    ShopMultiTargetField,
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
        shop_id: null,
        multi_target: { all_shops: false, shops: [] },
        link_type: "none",
        product_id: null,
        category_id: null,
        display_category_id: null,
        position: 0,
        is_active: true,
        image: null,
        custom_checkboxes: {},
      },
      errors: {},
      shops: [],
      shopSearchDebounce: null,
      products: [],
      categories: [],
    };
  },
  computed: {
    networkCatalogEnabled() {
      return useModuleConfigStore().isModuleEnabled("network_catalog");
    },
    // Мультивыбор магазинов — только при создании в сетевом каталоге;
    // редактирование остаётся пер-запись (MID-238)
    isNetworkCreate() {
      return this.networkCatalogEnabled && !this.isEdit;
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
    userShopId() {
      return this.user?.shop_id || this.user?.shop?.id || this.user?.shops?.[0]?.shop_id || this.user?.shops?.[0]?.shop?.id;
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
            shop_id: shopId ? parseInt(shopId) : null,
            link_type: (data.link_type || "none").toLowerCase(),
            product_id: data.product_id ? parseInt(data.product_id) : (data.product?.id ? parseInt(data.product.id) : null),
            category_id: data.category_id ? parseInt(data.category_id) : (data.category?.id ? parseInt(data.category.id) : null),
            display_category_id: data.display_category_id
              ? parseInt(data.display_category_id)
              : (data.display_category?.id ? parseInt(data.display_category.id) : null),
            position: data.position ?? 0,
            is_active: data.is_active ?? true,
            image: data.image || null,
            custom_checkboxes: parseCustomCheckboxes(data.custom_checkboxes),
          };
          
          if (shopId) {
            this.fetchProducts(parseInt(shopId));
          }
        } else if (!this.isAdmin && this.userShopId) {
          this.form.shop_id = parseInt(this.userShopId);
          this.fetchProducts(parseInt(this.userShopId));
        }
      },
    },
    'form.link_type'(newVal, oldVal) {
      if (newVal !== 'product' && oldVal === 'product') {
        this.form.product_id = null;
      }
      if (newVal !== 'category' && oldVal === 'category') {
        this.form.category_id = null;
      }
    },
    userShopId: {
      immediate: true,
      handler(shopId) {
        if (!this.isAdmin && shopId) {
          this.form.shop_id = parseInt(shopId);
          if (!this.products.length) {
            this.fetchProducts(parseInt(shopId));
          }
        }
      },
    },
  },
  mounted() {
    if (this.isAdmin) {
      this.fetchShops();
      // В сетевом каталоге товары для баннера доступны сразу — весь каталог
      // сети без выбора магазина (MID-238)
      if (this.isNetworkCreate) {
        this.fetchProducts(NETWORK_PICKER_SHOP_ID);
      }
    } else if (this.userShopId) {
      this.form.shop_id = parseInt(this.userShopId);
      this.fetchProducts(parseInt(this.userShopId));
    }
    this.fetchCategories();
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
    // first:100 без поиска не покрывает список (MID-238)
    onShopSearch(title) {
      clearTimeout(this.shopSearchDebounce);
      this.shopSearchDebounce = setTimeout(() => this.fetchShops(title), 300);
    },
    fetchProducts(shopId) {
      if (!shopId) return;
      
      this.apollo()
        .admin.query({
          query: SHOP_PRODUCTS_FOR_BANNER,
          variables: { shop_id: shopId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.products = data.shopProductsForSale?.data || [];
        })
        .catch((error) => {
          console.error("Error fetching products:", error);
        });
    },
    fetchCategories() {
      this.apollo()
        .admin.query({
          query: CATEGORIES,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.categories = data.categories || [];
        })
        .catch((error) => {
          console.error("Error fetching categories:", error);
        });
    },
    onShopChange() {
      this.form.product_id = null;
      if (this.form.shop_id) {
        this.fetchProducts(parseInt(this.form.shop_id));
      } else {
        this.products = [];
      }
    },
    validate() {
      this.errors = {};
      
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
      
      if (this.form.link_type === 'product' && !this.form.product_id) {
        this.errors.product_id = "Выберите товар";
      }
      
      if (this.form.link_type === 'category' && !this.form.category_id) {
        this.errors.category_id = "Выберите категорию";
      }
      
      return Object.keys(this.errors).length === 0;
    },
    submit() {
      if (!this.validate()) return;
      
      const data = {
        title: this.form.title.trim(),
        link_type: this.form.link_type.toUpperCase(),
        // null отправляем явно — на update это возвращает баннер на главную
        display_category_id: this.form.display_category_id ? parseInt(this.form.display_category_id) : null,
        position: this.form.position ? parseInt(this.form.position) : 0,
        is_active: this.form.is_active,
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

      if (this.form.link_type === 'product' && this.form.product_id) {
        data.product_id = parseInt(this.form.product_id);
      }
      
      if (this.form.link_type === 'category' && this.form.category_id) {
        data.category_id = parseInt(this.form.category_id);
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
.merchant-banner-form {
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
    
    &:disabled {
      background var(--gray-3)
      cursor not-allowed
    }
    
    &--error {
      border-color var(--red)
    }
    
    &--small {
      width 120px
    }
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
  
  &__radio-group {
    display flex
    flex-wrap wrap
    gap 16px
  }
  
  &__radio {
    display flex
    align-items center
    gap 8px
    cursor pointer
    font-size 0.875rem
    color var(--dark)
    
    input {
      display none
    }
  }
  
  &__radio-mark {
    width 20px
    height 20px
    border-radius 50%
    border 2px solid var(--gray-dark)
    position relative
    transition border-color 0.2s
    
    &::after {
      content ''
      position absolute
      width 10px
      height 10px
      background var(--pink)
      border-radius 50%
      top 50%
      left 50%
      transform translate(-50%, -50%) scale(0)
      transition transform 0.2s
    }
    
    input:checked + & {
      border-color var(--pink)
      
      &::after {
        transform translate(-50%, -50%) scale(1)
      }
    }
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
    max-width 400px
    
    .image-upload__container {
      width 100%
      height 200px
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
}
</style>
