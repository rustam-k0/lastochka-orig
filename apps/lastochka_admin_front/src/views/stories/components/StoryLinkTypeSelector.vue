<template>
  <div class="story-link-type">
    <div class="story-link-type__label">Тип перехода</div>
    <div class="story-link-type__options">
      <label 
        v-for="option in linkTypes" 
        :key="option.value" 
        class="story-link-type__option"
        :class="{ 'story-link-type__option--active': modelValue === option.value }"
      >
        <input 
          type="radio" 
          :value="option.value" 
          :checked="modelValue === option.value"
          @change="$emit('update:modelValue', option.value)"
        />
        <IconComponent :name="option.icon" />
        <span>{{ option.label }}</span>
      </label>
    </div>

    <!-- Условные поля -->
    <div v-if="modelValue === 'PRODUCT'" class="story-link-type__field">
      <div class="story-link-type__field-label">Выберите товар</div>
      <div class="story-link-type__autocomplete">
        <input 
          type="text"
          v-model="productSearch"
          @input="searchProducts"
          placeholder="Начните вводить название товара..."
          class="story-link-type__input"
        />
        <div v-if="productsLoading" class="story-link-type__loading">
          <LoadingIndicator />
        </div>
        <div v-if="productsList.length && productSearch" class="story-link-type__dropdown">
          <div 
            v-for="product in productsList" 
            :key="product.id"
            class="story-link-type__dropdown-item"
            @click="selectProduct(product)"
          >
            {{ product.title }}
          </div>
        </div>
        <div v-if="selectedProduct" class="story-link-type__selected">
          <span>{{ selectedProduct.title }}</span>
          <button @click="clearProduct" type="button">
            <IconComponent name="close-line" />
          </button>
        </div>
      </div>
    </div>

    <div v-if="modelValue === 'SHOP'" class="story-link-type__field">
      <div class="story-link-type__field-label">Выберите магазин</div>
      <div class="story-link-type__autocomplete">
        <input 
          type="text"
          v-model="shopSearch"
          @input="searchShops"
          placeholder="Начните вводить название магазина..."
          class="story-link-type__input"
        />
        <div v-if="shopsLoading" class="story-link-type__loading">
          <LoadingIndicator />
        </div>
        <div v-if="shopsList.length && shopSearch" class="story-link-type__dropdown">
          <div 
            v-for="shop in shopsList" 
            :key="shop.id"
            class="story-link-type__dropdown-item"
            @click="selectShop(shop)"
          >
            {{ shop.title }}
          </div>
        </div>
        <div v-if="selectedShop" class="story-link-type__selected">
          <span>{{ selectedShop.title }}</span>
          <button @click="clearShop" type="button">
            <IconComponent name="close-line" />
          </button>
        </div>
      </div>
    </div>

    <div v-if="modelValue === 'EXTERNAL'" class="story-link-type__field">
      <div class="story-link-type__field-label">Внешняя ссылка</div>
      <input 
        type="url"
        :value="externalUrl"
        @input="$emit('update:externalUrl', $event.target.value)"
        placeholder="https://example.com"
        class="story-link-type__input"
      />
      <span v-if="urlError" class="story-link-type__error">{{ urlError }}</span>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import PRODUCTS_SEARCH from "@/graphql/queries/admin_products_search.graphql";
import SHOPS_SEARCH from "@/graphql/queries/admin_shops_search.graphql";

export default {
  name: "StoryLinkTypeSelector",
  inject: ["apollo"],
  components: { IconComponent, LoadingIndicator },
  props: {
    modelValue: {
      type: String,
      default: "none",
    },
    productId: {
      type: [Number, null],
      default: null,
    },
    shopId: {
      type: [Number, null],
      default: null,
    },
    externalUrl: {
      type: String,
      default: "",
    },
    initialProduct: {
      type: Object,
      default: null,
    },
    initialShop: {
      type: Object,
      default: null,
    },
  },
  emits: ["update:modelValue", "update:productId", "update:shopId", "update:externalUrl"],
  data() {
    return {
      linkTypes: [
        { value: "NONE", label: "Не выбран", icon: "forbid-line" },
        { value: "PRODUCT", label: "На товар", icon: "shopping-bag-line" },
        { value: "SHOP", label: "На магазин", icon: "store-2-line" },
        { value: "EXTERNAL", label: "Внешняя ссылка", icon: "external-link-line" },
      ],
      productSearch: "",
      shopSearch: "",
      productsList: [],
      shopsList: [],
      selectedProduct: null,
      selectedShop: null,
      productsLoading: false,
      shopsLoading: false,
      searchTimeout: null,
    };
  },
  computed: {
    urlError() {
      if (!this.externalUrl) return "";
      try {
        new URL(this.externalUrl);
        return "";
      } catch {
        return "Введите корректный URL";
      }
    },
  },
  watch: {
    initialProduct: {
      immediate: true,
      handler(val) {
        if (val) {
          this.selectedProduct = val;
        }
      },
    },
    initialShop: {
      immediate: true,
      handler(val) {
        if (val) {
          this.selectedShop = val;
        }
      },
    },
  },
  methods: {
    searchProducts() {
      clearTimeout(this.searchTimeout);
      if (!this.productSearch) {
        this.productsList = [];
        return;
      }
      this.searchTimeout = setTimeout(() => {
        this.productsLoading = true;
        this.apollo()
          .admin.query({
            query: PRODUCTS_SEARCH,
            variables: {
              title: this.productSearch,
              first: 10,
            },
          })
          .then(({ data }) => {
            this.productsList = data.admin_products_paginate?.data || [];
          })
          .finally(() => {
            this.productsLoading = false;
          });
      }, 300);
    },
    searchShops() {
      clearTimeout(this.searchTimeout);
      if (!this.shopSearch) {
        this.shopsList = [];
        return;
      }
      this.searchTimeout = setTimeout(() => {
        this.shopsLoading = true;
        this.apollo()
          .admin.query({
            query: SHOPS_SEARCH,
            variables: {
              title: this.shopSearch,
              first: 10,
            },
          })
          .then(({ data }) => {
            this.shopsList = data.shops_paginate?.data || [];
          })
          .finally(() => {
            this.shopsLoading = false;
          });
      }, 300);
    },
    selectProduct(product) {
      this.selectedProduct = product;
      this.productSearch = "";
      this.productsList = [];
      this.$emit("update:productId", product.id);
    },
    selectShop(shop) {
      this.selectedShop = shop;
      this.shopSearch = "";
      this.shopsList = [];
      this.$emit("update:shopId", shop.id);
    },
    clearProduct() {
      this.selectedProduct = null;
      this.$emit("update:productId", null);
    },
    clearShop() {
      this.selectedShop = null;
      this.$emit("update:shopId", null);
    },
  },
};
</script>

<style lang="stylus">
.story-link-type {
  &__label {
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    margin-bottom 12px
  }
  
  &__options {
    display flex
    flex-wrap wrap
    gap 12px
    margin-bottom 16px
  }
  
  &__option {
    display flex
    align-items center
    gap 8px
    padding 12px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    cursor pointer
    transition all 0.2s
    
    input {
      display none
    }
    
    .icon {
      width 18px
      height 18px
      
      svg path {
        fill var(--dark-light)
        transition fill 0.2s
      }
    }
    
    span {
      font-size 0.875rem
      color var(--dark)
    }
    
    &:hover {
      border-color var(--pink)
    }
    
    &--active {
      border-color var(--pink)
      background var(--pink-light, rgba(255, 105, 180, 0.1))
      
      .icon svg path {
        fill var(--pink)
      }
      
      span {
        color var(--pink)
      }
    }
  }
  
  &__field {
    margin-top 16px
  }
  
  &__field-label {
    font-weight 500
    font-size 0.875rem
    color var(--dark)
    margin-bottom 8px
  }
  
  &__autocomplete {
    position relative
  }
  
  &__input {
    width 100%
    padding 12px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem
    outline none
    transition border-color 0.2s
    
    &:focus {
      border-color var(--pink)
    }
  }
  
  &__loading {
    position absolute
    right 12px
    top 50%
    transform translateY(-50%)
  }
  
  &__dropdown {
    position absolute
    top 100%
    left 0
    right 0
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    margin-top 4px
    max-height 200px
    overflow-y auto
    z-index 10
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.1)
  }
  
  &__dropdown-item {
    padding 12px 16px
    cursor pointer
    transition background 0.2s
    
    &:hover {
      background var(--gray-3)
    }
  }
  
  &__selected {
    display flex
    align-items center
    justify-content space-between
    margin-top 8px
    padding 12px 16px
    background var(--gray-3)
    border-radius 8px
    
    span {
      font-size 0.875rem
      color var(--dark)
    }
    
    button {
      background none
      border none
      cursor pointer
      padding 4px
      display flex
      align-items center
      justify-content center
      
      .icon {
        width 16px
        height 16px
        
        svg path {
          fill var(--dark-light)
        }
      }
      
      &:hover .icon svg path {
        fill var(--red)
      }
    }
  }
  
  &__error {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--red)
  }
}
</style>
