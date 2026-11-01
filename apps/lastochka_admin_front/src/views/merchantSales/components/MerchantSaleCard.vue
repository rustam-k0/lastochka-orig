<template>
  <div class="merchant-sale-card">
    <div class="merchant-sale-card__image">
      <img v-if="data.image?.path" :src="imagePath" :alt="data.title" />
      <div v-else class="merchant-sale-card__image-placeholder">
        <IconComponent name="price-tag-3-line" />
      </div>
      <div class="merchant-sale-card__discount">-{{ data.discount_percent }}%</div>
    </div>
    
    <div class="merchant-sale-card__content">
      <div class="merchant-sale-card__header">
        <h3 class="merchant-sale-card__title">{{ data.title }}</h3>
        <SaleStatusBadge :status="computedStatus" />
      </div>
      
      <div class="merchant-sale-card__shop">
        <IconComponent name="store-2-line" />
        <span>{{ data.shop?.title || 'Не указан' }}</span>
      </div>
      
      <div class="merchant-sale-card__period">
        <IconComponent name="calendar-event-line" />
        <span>{{ formatPeriod }}</span>
      </div>
      
      <div class="merchant-sale-card__products">
        <IconComponent name="archive-line" />
        <span>{{ data.products_count || 0 }} товаров</span>
      </div>
    </div>
    
    <div class="merchant-sale-card__actions">
      <button 
        class="merchant-sale-card__action" 
        @click="$emit('edit')"
        title="Редактировать"
      >
        <IconComponent name="edit-line" />
      </button>
      <button 
        class="merchant-sale-card__action merchant-sale-card__action--toggle"
        :class="{ 'merchant-sale-card__action--active': data.is_active }"
        @click="$emit('toggle-active')"
        :title="data.is_active ? 'Деактивировать' : 'Активировать'"
      >
        <IconComponent :name="data.is_active ? 'eye-line' : 'eye-off-line'" />
      </button>
      <button 
        class="merchant-sale-card__action merchant-sale-card__action--delete" 
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
import SaleStatusBadge from "./SaleStatusBadge.vue";
import { useDefaultStore } from "@/stores/default.js";
import dayjs from "dayjs";

export default {
  name: "MerchantSaleCard",
  components: {
    IconComponent,
    SaleStatusBadge,
  },
  props: {
    data: {
      type: Object,
      required: true,
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
    formatPeriod() {
      const start = dayjs(this.data.starts_at).format("DD.MM.YYYY");
      const end = dayjs(this.data.ends_at).format("DD.MM.YYYY");
      return `${start} - ${end}`;
    },
    computedStatus() {
      if (!this.data.is_active) {
        return "inactive";
      }
      const now = dayjs();
      const start = dayjs(this.data.starts_at);
      const end = dayjs(this.data.ends_at);
      
      if (now.isBefore(start)) {
        return "upcoming";
      } else if (now.isAfter(end)) {
        return "expired";
      }
      return "active";
    },
  },
};
</script>

<style lang="stylus">
.merchant-sale-card {
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
  
  &__discount {
    position absolute
    top 12px
    right 12px
    background var(--pink)
    color var(--white)
    font-weight 600
    font-size 0.875rem
    padding 4px 10px
    border-radius 6px
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
  
  &__shop,
  &__period,
  &__products {
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
    &--toggle.merchant-sale-card__action--active {
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
