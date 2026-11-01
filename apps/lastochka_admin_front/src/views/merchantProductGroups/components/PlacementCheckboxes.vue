<template>
  <div class="placement-checkboxes">
    <label class="placement-checkboxes__label">Места размещения</label>

    <div class="placement-checkboxes__list">
      <label
        v-if="isAdmin && checkboxStore.isEnabled('MerchantProductGroup', 'show_on_main')"
        class="placement-checkboxes__item"
        :class="{ 'placement-checkboxes__item--active': showOnMain }"
      >
        <input type="checkbox" :checked="showOnMain" @change="$emit('update:showOnMain', $event.target.checked)" />
        <span class="placement-checkboxes__checkbox">
          <IconComponent v-if="showOnMain" name="check-line" />
        </span>
        <span class="placement-checkboxes__icon">🏠</span>
        <span class="placement-checkboxes__text">{{ checkboxStore.getLabel('MerchantProductGroup', 'show_on_main') }}</span>
      </label>

      <label
        v-if="checkboxStore.isEnabled('MerchantProductGroup', 'show_on_merchant_page')"
        class="placement-checkboxes__item"
        :class="{ 'placement-checkboxes__item--active': showOnMerchantPage }"
      >
        <input type="checkbox" :checked="showOnMerchantPage" @change="$emit('update:showOnMerchantPage', $event.target.checked)" />
        <span class="placement-checkboxes__checkbox">
          <IconComponent v-if="showOnMerchantPage" name="check-line" />
        </span>
        <span class="placement-checkboxes__icon">🏪</span>
        <span class="placement-checkboxes__text">{{ checkboxStore.getLabel('MerchantProductGroup', 'show_on_merchant_page') }}</span>
      </label>

      <label
        v-if="checkboxStore.isEnabled('MerchantProductGroup', 'show_in_merchant_catalog')"
        class="placement-checkboxes__item"
        :class="{ 'placement-checkboxes__item--active': showInMerchantCatalog }"
      >
        <input type="checkbox" :checked="showInMerchantCatalog" @change="$emit('update:showInMerchantCatalog', $event.target.checked)" />
        <span class="placement-checkboxes__checkbox">
          <IconComponent v-if="showInMerchantCatalog" name="check-line" />
        </span>
        <span class="placement-checkboxes__icon">📂</span>
        <span class="placement-checkboxes__text">{{ checkboxStore.getLabel('MerchantProductGroup', 'show_in_merchant_catalog') }}</span>
      </label>

      <label
        v-if="checkboxStore.isEnabled('MerchantProductGroup', 'show_on_search')"
        class="placement-checkboxes__item"
        :class="{ 'placement-checkboxes__item--active': showOnSearch }"
      >
        <input type="checkbox" :checked="showOnSearch" @change="$emit('update:showOnSearch', $event.target.checked)" />
        <span class="placement-checkboxes__checkbox">
          <IconComponent v-if="showOnSearch" name="check-line" />
        </span>
        <span class="placement-checkboxes__icon">🔍</span>
        <span class="placement-checkboxes__text">{{ checkboxStore.getLabel('MerchantProductGroup', 'show_on_search') }}</span>
      </label>

      <label
        v-if="checkboxStore.isEnabled('MerchantProductGroup', 'is_recommended')"
        class="placement-checkboxes__item"
        :class="{ 'placement-checkboxes__item--active': isRecommended }"
      >
        <input type="checkbox" :checked="isRecommended" @change="$emit('update:isRecommended', $event.target.checked)" />
        <span class="placement-checkboxes__checkbox">
          <IconComponent v-if="isRecommended" name="check-line" />
        </span>
        <span class="placement-checkboxes__icon">⭐</span>
        <span class="placement-checkboxes__text">{{ checkboxStore.getLabel('MerchantProductGroup', 'is_recommended') }}</span>
      </label>

      <label
        v-if="checkboxStore.isEnabled('MerchantProductGroup', 'is_cart_page')"
        class="placement-checkboxes__item"
        :class="{ 'placement-checkboxes__item--active': isCartPage }"
      >
        <input type="checkbox" :checked="isCartPage" @change="$emit('update:isCartPage', $event.target.checked)" />
        <span class="placement-checkboxes__checkbox">
          <IconComponent v-if="isCartPage" name="check-line" />
        </span>
        <span class="placement-checkboxes__icon">🛒</span>
        <span class="placement-checkboxes__text">{{ checkboxStore.getLabel('MerchantProductGroup', 'is_cart_page') }}</span>
      </label>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";

export default {
  name: "PlacementCheckboxes",
  components: {
    IconComponent,
  },
  props: {
    showOnMain: { type: Boolean, default: false },
    showOnMerchantPage: { type: Boolean, default: false },
    showInMerchantCatalog: { type: Boolean, default: false },
    showOnSearch: { type: Boolean, default: false },
    isRecommended: { type: Boolean, default: false },
    isCartPage: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
  },
  emits: [
    "update:showOnMain", "update:showOnMerchantPage", "update:showInMerchantCatalog",
    "update:showOnSearch", "update:isRecommended", "update:isCartPage",
  ],
  computed: {
    checkboxStore() {
      return useCheckboxConfigStore();
    },
  },
};
</script>

<style lang="stylus">
.placement-checkboxes {
  &__label {
    display block
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    margin-bottom 12px
  }

  &__list {
    display flex
    flex-direction column
    gap 12px
  }

  &__item {
    display flex
    align-items center
    gap 12px
    padding 12px 16px
    background var(--gray-3)
    border-radius 8px
    cursor pointer
    transition background 0.2s

    input {
      display none
    }

    &:hover {
      background var(--gray-dark)
    }

    &--active {
      .placement-checkboxes__checkbox {
        background var(--pink)
        border-color var(--pink)

        .icon svg path {
          fill var(--white)
        }
      }
    }
  }

  &__checkbox {
    width 20px
    height 20px
    border 1px solid var(--border-color)
    border-radius 4px
    background var(--white)
    display flex
    align-items center
    justify-content center
    flex-shrink 0
    transition all 0.2s

    .icon {
      width 14px
      height 14px

      svg path {
        fill var(--white)
      }
    }
  }

  &__icon {
    font-size 1.25rem
  }

  &__text {
    font-size 0.875rem
    color var(--dark)
  }
}
</style>
