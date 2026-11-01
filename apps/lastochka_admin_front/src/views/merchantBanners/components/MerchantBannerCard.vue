<template>
  <div class="merchant-banner-card">
    <div class="merchant-banner-card__image">
      <img v-if="data.image?.path" :src="imagePath" :alt="data.title" />
      <div v-else class="merchant-banner-card__image-placeholder">
        <IconComponent name="image-line" />
      </div>
    </div>
    
    <div class="merchant-banner-card__content">
      <div class="merchant-banner-card__header">
        <h3 class="merchant-banner-card__title">{{ data.title }}</h3>
        <span 
          class="merchant-banner-card__status"
          :class="{ 'merchant-banner-card__status--active': data.is_active }"
        >
          {{ data.is_active ? 'Активен' : 'Неактивен' }}
        </span>
      </div>
      
      <div v-if="showShop" class="merchant-banner-card__shop">
        <IconComponent name="store-2-line" />
        <span>{{ data.shop?.title || 'Не указан' }}</span>
      </div>
      
      <div class="merchant-banner-card__link-type">
        <IconComponent name="layout-line" />
        <span>{{ placementText }}</span>
      </div>

      <div class="merchant-banner-card__link-type">
        <IconComponent :name="linkTypeIcon" />
        <span>{{ linkTypeText }}</span>
      </div>
      
      <div v-if="data.position !== null && data.position !== undefined" class="merchant-banner-card__position">
        <IconComponent name="sort-asc" />
        <span>Позиция: {{ data.position }}</span>
      </div>
    </div>
    
    <div class="merchant-banner-card__actions">
      <button 
        class="merchant-banner-card__action" 
        @click="$emit('edit')"
        title="Редактировать"
      >
        <IconComponent name="edit-line" />
      </button>
      <button 
        class="merchant-banner-card__action merchant-banner-card__action--toggle"
        :class="{ 'merchant-banner-card__action--active': data.is_active }"
        @click="$emit('toggle-active')"
        :title="data.is_active ? 'Деактивировать' : 'Активировать'"
      >
        <IconComponent :name="data.is_active ? 'eye-line' : 'eye-off-line'" />
      </button>
      <button 
        class="merchant-banner-card__action merchant-banner-card__action--delete" 
        @click="$emit('delete')"
        title="Удалить"
      >
        <IconComponent name="delete-bin-line" />
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "MerchantBannerCard",
  components: {
    IconComponent,
  },
  props: {
    data: {
      type: Object,
      required: true,
    },
    showShop: {
      type: Boolean,
      default: true,
    },
    isAdmin: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["edit", "delete", "toggle-active"],
  computed: {
    media_endpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
    imagePath() {
      if (this.data.image?.path) {
        if (this.data.image.path.startsWith("http") || this.data.image.path.startsWith("data:")) {
          return this.data.image.path;
        }
        return this.media_endpoint + this.data.image.path;
      }
      return null;
    },
    linkTypeIcon() {
      switch (this.data.link_type) {
        case 'PRODUCT':
          return 'price-tag-3-line';
        case 'CATEGORY':
          return 'folder-line';
        default:
          return 'subtract-line';
      }
    },
    linkTypeText() {
      switch (this.data.link_type) {
        case 'PRODUCT':
          return `На товар: ${this.data.product?.title || 'Не указан'}`;
        case 'CATEGORY':
          return `На категорию: ${this.data.category?.title || 'Не указана'}`;
        default:
          return 'Без перехода';
      }
    },
    placementText() {
      if (this.data.display_category_id || this.data.display_category) {
        return `Показ: ${this.data.display_category?.title || 'категория'}`;
      }
      return 'Показ: Главная страница';
    },
  },
};
</script>

<style lang="stylus">
.merchant-banner-card {
  background var(--white)
  border 1px solid var(--gray-dark)
  border-radius 12px
  overflow hidden
  transition box-shadow 0.2s
  
  &:hover {
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.08)
  }
  
  &__image {
    position relative
    width 100%
    height 140px
    background var(--gray-3)
    
    img {
      width 100%
      height 100%
      object-fit cover
    }
  }
  
  &__image-placeholder {
    width 100%
    height 100%
    display flex
    align-items center
    justify-content center
    
    .icon {
      width 48px
      height 48px
      
      svg path {
        fill var(--gray-light)
      }
    }
  }
  
  &__content {
    padding 16px
    display flex
    flex-direction column
    gap 10px
  }
  
  &__header {
    display flex
    align-items flex-start
    justify-content space-between
    gap 12px
  }
  
  &__title {
    font-size 1rem
    font-weight 600
    color var(--dark)
    margin 0
    line-height 1.3
    overflow hidden
    text-overflow ellipsis
    display -webkit-box
    -webkit-line-clamp 2
    -webkit-box-orient vertical
  }
  
  &__status {
    flex-shrink 0
    font-size 0.75rem
    font-weight 500
    padding 4px 8px
    border-radius 4px
    background var(--gray-dark)
    color var(--dark-light)
    
    &--active {
      background var(--green)
      color var(--white)
    }
  }
  
  &__shop,
  &__link-type,
  &__position {
    display flex
    align-items center
    gap 8px
    font-size 0.813rem
    color var(--dark-light)
    
    .icon {
      width 16px
      height 16px
      flex-shrink 0
      
      svg path {
        fill var(--dark-light)
      }
    }
  }
  
  &__link-type {
    span {
      overflow hidden
      text-overflow ellipsis
      white-space nowrap
    }
  }
  
  &__actions {
    display flex
    border-top 1px solid var(--gray-dark)
    
    button {
      flex 1
      display flex
      align-items center
      justify-content center
      padding 12px
      background none
      border none
      border-right 1px solid var(--gray-dark)
      cursor pointer
      transition background 0.2s
      
      &:last-child {
        border-right none
      }
      
      &:hover {
        background var(--gray-3)
      }
      
      .icon {
        width 18px
        height 18px
        
        svg path {
          fill var(--dark)
        }
      }
    }
  }
  
  &__action {
    &--toggle.merchant-banner-card__action--active {
      .icon svg path {
        fill var(--green)
      }
    }
    
    &--delete:hover {
      .icon svg path {
        fill var(--red)
      }
    }
  }
}
</style>
