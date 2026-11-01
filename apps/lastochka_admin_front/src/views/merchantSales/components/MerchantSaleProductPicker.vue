<template>
  <div class="product-picker">
    <div class="product-picker__header">
      <label class="product-picker__label">
        Товары <span class="required">*</span>
      </label>
      <span v-if="selectedProducts.length" class="product-picker__count">
        Выбрано: {{ selectedProducts.length }}
      </span>
    </div>
    
    <div v-if="!shopId" class="product-picker__empty">
      <IconComponent name="information-line" />
      <span>Сначала выберите магазин</span>
    </div>
    
    <template v-else>
      <div class="product-picker__search">
        <IconComponent name="search-line" />
        <input
          type="text"
          v-model="search"
          placeholder="Поиск товаров..."
          @input="debouncedSearch"
        />
      </div>
      
      <div class="product-picker__controls">
        <button type="button" class="product-picker__btn" @click="selectAll">
          Выбрать все
        </button>
        <button type="button" class="product-picker__btn" @click="deselectAll">
          Снять все
        </button>
      </div>
      
      <div v-if="loading" class="product-picker__loading">
        <LoadingIndicator />
      </div>
      
      <div v-else-if="!products.length" class="product-picker__empty">
        <IconComponent name="archive-line" />
        <span>{{ search ? 'Товары не найдены' : 'Нет товаров у магазина' }}</span>
      </div>
      
      <div v-else class="product-picker__list">
        <div
          v-for="product in products"
          :key="product.id"
          class="product-picker__item"
          :class="{ 'product-picker__item--selected': isSelected(product.id) }"
          @click="toggleProduct(product)"
        >
          <div class="product-picker__checkbox">
            <IconComponent :name="isSelected(product.id) ? 'checkbox-fill' : 'checkbox-blank-line'" />
          </div>
          <div class="product-picker__image">
            <img v-if="getProductImage(product)" :src="getProductImage(product)" :alt="product.title" />
            <IconComponent v-else name="image-line" />
          </div>
          <div class="product-picker__info">
            <span class="product-picker__name">{{ product.title }}</span>
            <div class="product-picker__prices" v-if="product.default_price?.value">
              <span 
                class="product-picker__price-old" 
                :class="{ 'product-picker__price-old--no-discount': !discountPercent }"
              >{{ formatPrice(product.default_price.value) }}</span>
              <span v-if="discountPercent > 0" class="product-picker__price-new">
                {{ formatDiscountedPrice(product.default_price.value) }}
              </span>
            </div>
          </div>
        </div>
      </div>
      
      <div v-if="paginatorInfo.lastPage > 1" class="product-picker__pagination">
        <button 
          type="button"
          :disabled="page <= 1"
          @click="page--"
          class="product-picker__btn"
        >
          Назад
        </button>
        <span>{{ page }} из {{ paginatorInfo.lastPage }}</span>
        <button 
          type="button"
          :disabled="page >= paginatorInfo.lastPage"
          @click="page++"
          class="product-picker__btn"
        >
          Вперёд
        </button>
      </div>
    </template>
    
    <span v-if="error" class="product-picker__error">{{ error }}</span>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import { useDefaultStore } from "@/stores/default.js";
import SHOP_PRODUCTS_FOR_SALE from "@/graphql/queries/shop_products_for_sale.graphql";

export default {
  name: "MerchantSaleProductPicker",
  inject: ["apollo"],
  components: {
    IconComponent,
    LoadingIndicator,
  },
  props: {
    modelValue: {
      type: Array,
      default: () => [],
    },
    shopId: {
      type: [Number, String],
      default: null,
    },
    excludeSaleId: {
      type: [Number, String],
      default: null,
    },
    discountPercent: {
      type: Number,
      default: 0,
    },
    error: {
      type: String,
      default: "",
    },
  },
  emits: ["update:modelValue"],
  data() {
    return {
      products: [],
      loading: false,
      search: "",
      page: 1,
      first: 20,
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
      },
      searchTimeout: null,
    };
  },
  computed: {
    selectedProducts: {
      get() {
        return this.modelValue || [];
      },
      set(value) {
        this.$emit("update:modelValue", value);
      },
    },
  },
  watch: {
    shopId: {
      immediate: true,
      handler(newVal, oldVal) {
        if (newVal) {
          this.page = 1;
          this.search = "";
          this.fetchProducts();
        } else {
          this.products = [];
        }
      },
    },
    page() {
      this.fetchProducts();
    },
  },
  methods: {
    fetchProducts() {
      if (!this.shopId) return;
      
      this.loading = true;
      this.apollo()
        .admin.query({
          query: SHOP_PRODUCTS_FOR_SALE,
          variables: {
            shop_id: parseInt(this.shopId),
            exclude_sale_id: this.excludeSaleId ? parseInt(this.excludeSaleId) : undefined,
            search: this.search || undefined,
            first: this.first,
            page: this.page,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const fetchedProducts = data.shopProductsForSale?.data || [];
          const fetchedIds = fetchedProducts.map(p => parseInt(p.id));
          
          // Добавляем выбранные товары которых нет в ответе API (они могли быть исключены)
          const missingSelected = this.selectedProducts.filter(
            p => !fetchedIds.includes(parseInt(p.id))
          );
          
          this.products = [...missingSelected, ...fetchedProducts];
          this.paginatorInfo = data.shopProductsForSale?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching products:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    debouncedSearch() {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        this.page = 1;
        this.fetchProducts();
      }, 300);
    },
    isSelected(productId) {
      return this.selectedProducts.some((p) => parseInt(p.id) === parseInt(productId));
    },
    toggleProduct(product) {
      const index = this.selectedProducts.findIndex((p) => parseInt(p.id) === parseInt(product.id));
      if (index > -1) {
        const newSelected = [...this.selectedProducts];
        newSelected.splice(index, 1);
        this.selectedProducts = newSelected;
      } else {
        this.selectedProducts = [...this.selectedProducts, product];
      }
    },
    selectAll() {
      const newSelected = [...this.selectedProducts];
      this.products.forEach((product) => {
        if (!this.isSelected(product.id)) {
          newSelected.push(product);
        }
      });
      this.selectedProducts = newSelected;
    },
    deselectAll() {
      const productIds = this.products.map((p) => parseInt(p.id));
      this.selectedProducts = this.selectedProducts.filter(
        (p) => !productIds.includes(parseInt(p.id))
      );
    },
    getProductImage(product) {
      const img = product.images?.[0]?.path;
      if (img) {
        if (img.startsWith("http") || img.startsWith("data:")) {
          return img;
        }
        return useDefaultStore().MEDIA_ENDPOINT + img;
      }
      return null;
    },
    formatPrice(value) {
      return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        minimumFractionDigits: 0,
      }).format(value);
    },
    formatDiscountedPrice(value) {
      const discounted = value * (1 - this.discountPercent / 100);
      return this.formatPrice(discounted);
    },
  },
};
</script>

<style lang="stylus">
.product-picker {
  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 12px
  }
  
  &__label {
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    
    .required {
      color var(--red)
    }
  }
  
  &__count {
    font-size 0.813rem
    color var(--pink)
    font-weight 500
  }
  
  &__search {
    display flex
    align-items center
    gap 8px
    padding 12px 16px
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    margin-bottom 12px
    
    .icon {
      width 18px
      height 18px
      flex-shrink 0
      
      svg path {
        fill var(--dark-light)
      }
    }
    
    input {
      border none
      outline none
      width 100%
      font-size 0.875rem
      
      &::placeholder {
        color var(--dark-light)
      }
    }
  }
  
  &__controls {
    display flex
    gap 8px
    margin-bottom 12px
  }
  
  &__btn {
    padding 8px 16px
    border 1px solid var(--gray-dark)
    border-radius 6px
    background var(--white)
    font-size 0.813rem
    color var(--dark)
    cursor pointer
    transition all 0.2s
    
    &:hover:not(:disabled) {
      border-color var(--pink)
      color var(--pink)
    }
    
    &:disabled {
      opacity 0.5
      cursor not-allowed
    }
  }
  
  &__loading {
    display flex
    justify-content center
    padding 32px
  }
  
  &__empty {
    display flex
    flex-direction column
    align-items center
    gap 8px
    padding 32px
    background var(--gray-3)
    border-radius 8px
    color var(--dark-light)
    font-size 0.875rem
    
    .icon {
      width 32px
      height 32px
      
      svg path {
        fill var(--dark-light)
      }
    }
  }
  
  &__list {
    max-height 400px
    overflow-y auto
    border 1px solid var(--gray-dark)
    border-radius 8px
  }
  
  &__item {
    display flex
    align-items center
    gap 12px
    padding 12px
    border-bottom 1px solid var(--gray-dark)
    cursor pointer
    transition background 0.2s
    
    &:last-child {
      border-bottom none
    }
    
    &:hover {
      background var(--gray-3)
    }
    
    &--selected {
      background rgba(236, 72, 153, 0.05)
      
      &:hover {
        background rgba(236, 72, 153, 0.1)
      }
    }
  }
  
  &__checkbox {
    .icon {
      width 20px
      height 20px
      
      svg path {
        fill var(--gray-light)
      }
    }
    
    .product-picker__item--selected & {
      .icon svg path {
        fill var(--pink)
      }
    }
  }
  
  &__image {
    width 48px
    height 48px
    border-radius 6px
    background var(--gray-3)
    overflow hidden
    flex-shrink 0
    display flex
    align-items center
    justify-content center
    
    img {
      width 100%
      height 100%
      object-fit cover
    }
    
    .icon {
      width 24px
      height 24px
      
      svg path {
        fill var(--gray-light)
      }
    }
  }
  
  &__info {
    flex 1
    min-width 0
  }
  
  &__name {
    display block
    font-size 0.875rem
    color var(--dark)
    margin-bottom 4px
    overflow hidden
    text-overflow ellipsis
    white-space nowrap
  }
  
  &__prices {
    display flex
    align-items center
    gap 8px
  }
  
  &__price-old {
    font-size 0.75rem
    color var(--dark-light)
    text-decoration line-through
    
    &--no-discount {
      text-decoration none
      font-size 0.875rem
      color var(--dark)
    }
  }
  
  &__price-new {
    font-size 0.875rem
    font-weight 600
    color var(--green)
  }
  
  &__pagination {
    display flex
    justify-content center
    align-items center
    gap 12px
    margin-top 12px
    
    span {
      font-size 0.813rem
      color var(--dark)
    }
  }
  
  &__error {
    display block
    margin-top 8px
    font-size 0.75rem
    color var(--red)
  }
}
</style>
