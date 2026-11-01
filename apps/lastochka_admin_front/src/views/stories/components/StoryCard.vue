<template>
  <div 
    class="story-card" 
    :class="{ 
      'story-card--expired': isExpired, 
      'story-card--inactive': !data.is_active 
    }"
    draggable="true"
    @dragstart="$emit('dragstart', $event)"
    @dragover="$emit('dragover', $event)"
    @drop="$emit('drop', $event)"
  >
    <div class="story-card__image">
      <img 
        v-if="data.thumbnailImage" 
        :src="media_endpoint + data.thumbnailImage.path" 
        :alt="data.title" 
      />
      <div v-else class="story-card__placeholder">
        <IconComponent name="image-line" />
      </div>
      <div v-if="isExpired" class="story-card__overlay">
        <span>Истекла</span>
      </div>
    </div>
    <div class="story-card__content">
      <h4 class="story-card__title">{{ data.title }}</h4>
      <div class="story-card__meta">
        <span class="story-card__date" :class="{ 'story-card__date--expired': isExpired }">
          <IconComponent name="calendar-line" />
          {{ formatDate(data.expires_at) }}
        </span>
        <span class="story-card__link-type">
          <IconComponent :name="linkTypeIcon" />
          {{ linkTypeLabel }}
        </span>
      </div>
      <div class="story-card__info">
        <span class="story-card__images-count">
          <IconComponent name="image-2-line" />
          {{ data.images?.length || 0 }} изображений
        </span>
        <span 
          class="story-card__status" 
          :class="{ 'story-card__status--active': data.is_active }"
        >
          {{ data.is_active ? 'Активна' : 'Неактивна' }}
        </span>
      </div>
    </div>
    <div class="story-card__actions">
      <button 
        class="story-card__action" 
        @click="$emit('toggle-active', data)"
        :title="data.is_active ? 'Деактивировать' : 'Активировать'"
      >
        <IconComponent :name="data.is_active ? 'eye-off-line' : 'eye-line'" />
      </button>
      <button 
        class="story-card__action" 
        @click="$emit('edit', data)"
        title="Редактировать"
      >
        <IconComponent name="pencil-line" />
      </button>
      <button 
        class="story-card__action story-card__action--danger" 
        @click="$emit('delete', data)"
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
import dayjs from "dayjs";

export default {
  name: "StoryCard",
  components: { IconComponent },
  props: {
    data: {
      type: Object,
      required: true,
    },
  },
  emits: ["edit", "delete", "toggle-active", "dragstart", "dragover", "drop"],
  computed: {
    media_endpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
    isExpired() {
      return dayjs(this.data.expires_at).isBefore(dayjs());
    },
    linkTypeIcon() {
      const icons = {
        none: "forbid-line",
        product: "shopping-bag-line",
        shop: "store-2-line",
        external: "external-link-line",
      };
      return icons[this.data.link_type?.toLowerCase()] || icons.none;
    },
    linkTypeLabel() {
      const labels = {
        none: "Без перехода",
        product: "На товар",
        shop: "На магазин",
        external: "Внешняя ссылка",
      };
      return labels[this.data.link_type?.toLowerCase()] || labels.none;
    },
  },
  methods: {
    formatDate(date) {
      return dayjs(date).format("DD.MM.YYYY HH:mm");
    },
  },
};
</script>

<style lang="stylus">
.story-card {
  background var(--white)
  border-radius 12px
  border 1px solid var(--gray-dark)
  overflow hidden
  transition box-shadow 0.2s, transform 0.2s
  cursor grab
  
  &:hover {
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.1)
  }
  
  &:active {
    cursor grabbing
  }
  
  &--expired {
    opacity 0.7
  }
  
  &--inactive {
    .story-card__status {
      background var(--gray-light)
      color var(--dark-light)
    }
  }
  
  &__image {
    position relative
    width 100%
    padding-top 177.78% // 9:16 aspect ratio
    background var(--gray-3)
    overflow hidden
    
    img {
      position absolute
      top 0
      left 0
      width 100%
      height 100%
      object-fit cover
    }
  }
  
  &__placeholder {
    position absolute
    top 0
    left 0
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
  
  &__overlay {
    position absolute
    top 0
    left 0
    width 100%
    height 100%
    background rgba(0, 0, 0, 0.5)
    display flex
    align-items center
    justify-content center
    
    span {
      background var(--red)
      color var(--white)
      padding 8px 16px
      border-radius 8px
      font-weight 600
      font-size 0.875rem
    }
  }
  
  &__content {
    padding 16px
  }
  
  &__title {
    font-size 1rem
    font-weight 600
    color var(--dark)
    margin-bottom 8px
    overflow hidden
    text-overflow ellipsis
    white-space nowrap
  }
  
  &__meta {
    display flex
    flex-direction column
    gap 6px
    margin-bottom 12px
  }
  
  &__date,
  &__link-type {
    display inline-flex
    align-items center
    gap 6px
    font-size 0.75rem
    color var(--dark-light)
    
    .icon {
      width 14px
      height 14px
      
      svg path {
        fill var(--dark-light)
      }
    }
    
    &--expired {
      color var(--red)
      
      .icon svg path {
        fill var(--red)
      }
    }
  }
  
  &__info {
    display flex
    justify-content space-between
    align-items center
  }
  
  &__images-count {
    display inline-flex
    align-items center
    gap 4px
    font-size 0.75rem
    color var(--dark-light)
    
    .icon {
      width 14px
      height 14px
      
      svg path {
        fill var(--dark-light)
      }
    }
  }
  
  &__status {
    font-size 0.75rem
    padding 4px 8px
    border-radius 4px
    background var(--gray-light)
    color var(--dark-light)
    
    &--active {
      background var(--green-light)
      color var(--green)
    }
  }
  
  &__actions {
    display flex
    border-top 1px solid var(--gray-dark)
    
    button {
      flex 1
      padding 12px
      display flex
      align-items center
      justify-content center
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
          fill var(--dark-light)
        }
      }
    }
  }
  
  &__action {
    &--danger {
      &:hover {
        background var(--red-light) !important
        
        .icon svg path {
          fill var(--red)
        }
      }
    }
  }
}
</style>
