<template>
  <div 
    class="product-group-item"
    :class="{ 'product-group-item--selected': selected, 'product-group-item--draggable': draggable }"
    @click="$emit('toggle')"
  >
    <div v-if="draggable" class="product-group-item__drag-handle">
      <IconComponent name="menu-line" />
    </div>
    
    <div v-if="!draggable" class="product-group-item__checkbox">
      <IconComponent :name="selected ? 'checkbox-fill' : 'checkbox-blank-line'" />
    </div>
    
    <div class="product-group-item__image">
      <img v-if="imagePath" :src="imagePath" :alt="product.title" />
      <IconComponent v-else name="image-line" />
    </div>
    
    <div class="product-group-item__info">
      <span class="product-group-item__name">{{ product.title }}</span>
      <span v-if="price" class="product-group-item__price">{{ formatPrice(price) }}</span>
    </div>
    
    <div v-if="showQuantity && removable" class="product-group-item__quantity" @click.stop>
      <input
        type="number"
        :value="product.quantity || 1"
        min="1"
        max="9999"
        @input="$emit('update:quantity', parseInt($event.target.value) || 1)"
      />
      <span>шт</span>
    </div>

    <button
      v-if="removable"
      type="button"
      class="product-group-item__remove"
      @click.stop="$emit('remove')"
      title="Удалить"
    >
      <IconComponent name="close-line" />
    </button>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "ProductGroupProductItem",
  components: {
    IconComponent,
  },
  props: {
    product: {
      type: Object,
      required: true,
    },
    selected: {
      type: Boolean,
      default: false,
    },
    draggable: {
      type: Boolean,
      default: false,
    },
    removable: {
      type: Boolean,
      default: false,
    },
    showQuantity: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["toggle", "remove", "update:quantity"],
  computed: {
    media_endpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
    imagePath() {
      const img = this.product.images?.[0]?.path;
      if (img) {
        if (img.startsWith("http") || img.startsWith("data:")) {
          return img;
        }
        return this.media_endpoint + img;
      }
      return null;
    },
    price() {
      return this.product.defaultPrice?.value || this.product.default_price?.value;
    },
  },
  methods: {
    formatPrice(value) {
      return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        minimumFractionDigits: 0,
      }).format(value);
    },
  },
};
</script>

<style lang="stylus">
.product-group-item {
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
  
  &--draggable {
    cursor grab
    
    &:active {
      cursor grabbing
    }
  }
  
  &__drag-handle {
    .icon {
      width 20px
      height 20px
      
      svg path {
        fill var(--dark-light)
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
    
    .product-group-item--selected & {
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
  
  &__price {
    font-size 0.813rem
    color var(--dark-light)
  }
  
  &__quantity {
    display flex
    align-items center
    gap 4px
    flex-shrink 0

    input {
      width 56px
      padding 4px 6px
      border 1px solid var(--gray-dark)
      border-radius 4px
      font-size 0.813rem
      text-align center
      outline none

      &:focus {
        border-color var(--pink)
      }
    }

    span {
      font-size 0.75rem
      color var(--dark-light)
    }
  }

  &__remove {
    width 32px
    height 32px
    display flex
    align-items center
    justify-content center
    background none
    border none
    border-radius 6px
    cursor pointer
    transition background 0.2s
    
    &:hover {
      background var(--gray-dark)
      
      .icon svg path {
        fill var(--red)
      }
    }
    
    .icon {
      width 18px
      height 18px
      
      svg path {
        fill var(--dark-light)
        transition fill 0.2s
      }
    }
  }
}
</style>
