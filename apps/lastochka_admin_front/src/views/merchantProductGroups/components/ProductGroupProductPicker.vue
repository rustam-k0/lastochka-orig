<template>
  <div class="product-group-picker">
    <div class="product-group-picker__header">
      <label class="product-group-picker__label">
        Товары
      </label>
      <span v-if="selectedProducts.length" class="product-group-picker__count">
        Выбрано: {{ selectedProducts.length }}
      </span>
    </div>
    
    <div v-if="!shopId" class="product-group-picker__empty">
      <IconComponent name="information-line" />
      <span>Сначала выберите магазин</span>
    </div>
    
    <template v-else>
      <div class="product-group-picker__columns">
        <!-- Левая колонка: доступные товары -->
        <div class="product-group-picker__column">
          <div class="product-group-picker__column-header">
            <span>Доступные товары</span>
          </div>
          
          <div class="product-group-picker__search">
            <IconComponent name="search-line" />
            <input
              type="text"
              v-model="search"
              placeholder="Поиск товаров..."
              @input="debouncedSearch"
            />
          </div>
          
          <div class="product-group-picker__controls">
            <button type="button" class="product-group-picker__btn" @click="selectAll">
              Выбрать все
            </button>
          </div>
          
          <div v-if="loading" class="product-group-picker__loading">
            <LoadingIndicator />
          </div>
          
          <div v-else-if="!availableProducts.length" class="product-group-picker__empty-list">
            <IconComponent name="archive-line" />
            <span>{{ search ? 'Товары не найдены' : 'Нет доступных товаров' }}</span>
          </div>
          
          <div v-else class="product-group-picker__list">
            <ProductGroupProductItem
              v-for="product in availableProducts"
              :key="product.id"
              :product="product"
              :selected="isSelected(product.id)"
              @toggle="toggleProduct(product)"
            />
          </div>
          
          <div v-if="paginatorInfo.lastPage > 1" class="product-group-picker__pagination">
            <button 
              type="button"
              :disabled="page <= 1"
              @click="page--"
              class="product-group-picker__btn"
            >
              ←
            </button>
            <span>{{ page }} / {{ paginatorInfo.lastPage }}</span>
            <button 
              type="button"
              :disabled="page >= paginatorInfo.lastPage"
              @click="page++"
              class="product-group-picker__btn"
            >
              →
            </button>
          </div>
        </div>
        
        <!-- Правая колонка: выбранные товары с сортировкой -->
        <div class="product-group-picker__column">
          <div class="product-group-picker__column-header">
            <span>Выбранные товары</span>
            <button 
              v-if="selectedProducts.length" 
              type="button" 
              class="product-group-picker__btn product-group-picker__btn--clear"
              @click="clearAll"
            >
              Очистить
            </button>
          </div>
          
          <div v-if="!selectedProducts.length" class="product-group-picker__empty-list">
            <IconComponent name="checkbox-blank-line" />
            <span>Выберите товары из списка слева</span>
          </div>
          
          <div v-else class="product-group-picker__list product-group-picker__list--draggable">
            <ProductGroupProductItem
              v-for="product in selectedProducts"
              :key="product.id"
              :product="product"
              :draggable="true"
              :removable="true"
              :showQuantity="showQuantity"
              @remove="removeProduct(product)"
              @update:quantity="updateQuantity(product, $event)"
            />
          </div>
        </div>
      </div>
    </template>
    
    <span v-if="error" class="product-group-picker__error">{{ error }}</span>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import ProductGroupProductItem from "./ProductGroupProductItem.vue";
import SHOP_PRODUCTS_FOR_GROUP from "@/graphql/queries/shop_products_for_group.graphql";

export default {
  name: "ProductGroupProductPicker",
  inject: ["apollo"],
  components: {
    IconComponent,
    LoadingIndicator,
    ProductGroupProductItem,
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
    error: {
      type: String,
      default: "",
    },
    showQuantity: {
      type: Boolean,
      default: false,
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
    availableProducts() {
      const selectedIds = this.selectedProducts.map(p => parseInt(p.id));
      return this.products.filter(p => !selectedIds.includes(parseInt(p.id)));
    },
  },
  watch: {
    shopId: {
      immediate: true,
      handler(newVal) {
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
          query: SHOP_PRODUCTS_FOR_GROUP,
          variables: {
            shop_id: parseInt(this.shopId),
            search: this.search || undefined,
            first: this.first,
            page: this.page,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.products = data.shopProductsForSale?.data || [];
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
      if (this.isSelected(product.id)) {
        this.removeProduct(product);
      } else {
        this.selectedProducts = [{ ...product, quantity: 1 }, ...this.selectedProducts];
      }
    },
    updateQuantity(product, quantity) {
      const newSelected = this.selectedProducts.map(p =>
        parseInt(p.id) === parseInt(product.id) ? { ...p, quantity } : p
      );
      this.selectedProducts = newSelected;
    },
    removeProduct(product) {
      const index = this.selectedProducts.findIndex((p) => parseInt(p.id) === parseInt(product.id));
      if (index > -1) {
        const newSelected = [...this.selectedProducts];
        newSelected.splice(index, 1);
        this.selectedProducts = newSelected;
      }
    },
    selectAll() {
      const newProducts = this.availableProducts.filter(
        (product) => !this.isSelected(product.id)
      );
      this.selectedProducts = [...newProducts, ...this.selectedProducts];
    },
    clearAll() {
      this.selectedProducts = [];
    },
  },
};
</script>

<style lang="stylus">
.product-group-picker {
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
  }
  
  &__count {
    font-size 0.813rem
    color var(--pink)
    font-weight 500
  }
  
  &__columns {
    display grid
    grid-template-columns 1fr 1fr
    gap 16px
    
    +below(900px) {
      grid-template-columns 1fr
    }
  }
  
  &__column {
    display flex
    flex-direction column
    border 1px solid var(--gray-dark)
    border-radius 8px
    overflow hidden
  }
  
  &__column-header {
    display flex
    justify-content space-between
    align-items center
    padding 12px 16px
    background var(--gray-3)
    border-bottom 1px solid var(--gray-dark)
    font-weight 600
    font-size 0.813rem
    color var(--dark)
  }
  
  &__search {
    display flex
    align-items center
    gap 8px
    padding 12px 16px
    background var(--white)
    border-bottom 1px solid var(--gray-dark)
    
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
    padding 8px 16px
    border-bottom 1px solid var(--gray-dark)
  }
  
  &__btn {
    padding 6px 12px
    border 1px solid var(--gray-dark)
    border-radius 6px
    background var(--white)
    font-size 0.75rem
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
    
    &--clear {
      border-color var(--red)
      color var(--red)
      
      &:hover {
        background var(--red)
        color var(--white)
      }
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
  
  &__empty-list {
    display flex
    flex-direction column
    align-items center
    gap 8px
    padding 32px
    color var(--dark-light)
    font-size 0.813rem
    
    .icon {
      width 24px
      height 24px
      
      svg path {
        fill var(--dark-light)
      }
    }
  }
  
  &__list {
    flex 1
    max-height 350px
    overflow-y auto
    
    &--draggable {
      .product-group-item {
        background var(--white)
      }
    }
  }
  
  &__pagination {
    display flex
    justify-content center
    align-items center
    gap 8px
    padding 8px
    border-top 1px solid var(--gray-dark)
    background var(--gray-3)
    
    span {
      font-size 0.75rem
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
