<template>
  <div class="placement-badges">
    <!-- Бейдж главной - только для админа -->
    <span 
      v-if="isAdmin && showOnMain" 
      class="placement-badges__badge placement-badges__badge--main"
      title="Главная"
    >
      🏠
    </span>
    <span 
      v-if="showOnMerchantPage" 
      class="placement-badges__badge placement-badges__badge--merchant"
      title="Страница магазина"
    >
      🏪
    </span>
    <span
      v-if="showInMerchantCatalog"
      class="placement-badges__badge placement-badges__badge--catalog"
      title="Каталог"
    >
      📂
    </span>
    <span
      v-if="showOnSearch"
      class="placement-badges__badge placement-badges__badge--search"
      title="Страница поиска"
    >
      🔍
    </span>
    <span v-if="!hasAnyPlacement" class="placement-badges__empty">—</span>
  </div>
</template>

<script>
export default {
  name: "PlacementBadges",
  props: {
    showOnMain: {
      type: Boolean,
      default: false,
    },
    showOnMerchantPage: {
      type: Boolean,
      default: false,
    },
    showInMerchantCatalog: {
      type: Boolean,
      default: false,
    },
    showOnSearch: {
      type: Boolean,
      default: false,
    },
    isAdmin: {
      type: Boolean,
      default: false,
    },
  },
  computed: {
    hasAnyPlacement() {
      const mainVisible = this.isAdmin && this.showOnMain;
      return mainVisible || this.showOnMerchantPage || this.showInMerchantCatalog || this.showOnSearch;
    },
  },
};
</script>

<style lang="stylus">
.placement-badges {
  display flex
  align-items center
  gap 6px
  
  &__badge {
    font-size 1rem
    cursor default
    
    &--main {
      // Главная
    }
    
    &--merchant {
      // Страница магазина
    }
    
    &--catalog {
      // Каталог
    }
  }
  
  &__empty {
    color var(--dark-light)
    font-size 0.875rem
  }
}
</style>
