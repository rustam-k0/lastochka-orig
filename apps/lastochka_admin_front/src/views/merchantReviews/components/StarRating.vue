<template>
  <div
    class="star-rating"
    :class="{
      'star-rating--readonly': readonly,
      'star-rating--small': small
    }"
  >
    <button
      v-for="star in 5"
      :key="star"
      type="button"
      class="star-rating__star"
      :class="{ 'star-rating__star--filled': star <= (hoverValue || modelValue) }"
      @click="!readonly && selectRating(star)"
      @mouseenter="!readonly && (hoverValue = star)"
      @mouseleave="hoverValue = 0"
      :disabled="readonly"
    >
      <!-- Обе иконки в DOM, переключение CSS-классом: смена рейтинга не ждёт
           асинхронной подгрузки SVG (InlineSvg), звёзды не «залипают» -->
      <IconComponent name="star-fill" class="star-rating__icon star-rating__icon--fill" />
      <IconComponent name="star-line" class="star-rating__icon star-rating__icon--line" />
    </button>
    <span v-if="showValue && modelValue" class="star-rating__value">({{ modelValue }})</span>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "StarRating",
  components: {
    IconComponent,
  },
  props: {
    modelValue: {
      type: Number,
      default: 0,
    },
    readonly: {
      type: Boolean,
      default: false,
    },
    small: {
      type: Boolean,
      default: false,
    },
    showValue: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["update:modelValue"],
  data() {
    return {
      hoverValue: 0,
    };
  },
  methods: {
    selectRating(value) {
      this.$emit("update:modelValue", value);
    },
  },
};
</script>

<style lang="stylus">
.star-rating {
  display inline-flex
  align-items center
  gap 2px

  &--small &__star {
    .icon {
      width 16px
      height 16px
    }
  }

  &__star {
    background none
    border none
    padding 0
    cursor pointer
    display flex
    align-items center
    justify-content center
    transition transform 0.1s

    &:hover:not(:disabled) {
      transform scale(1.1)
    }

    &:disabled {
      cursor default
    }

    .icon {
      width 24px
      height 24px

      svg path {
        fill var(--gray-dark)
        transition fill 0.2s
      }
    }

    .star-rating__icon--fill {
      display none
    }

    &--filled {
      .star-rating__icon--fill {
        display flex
      }

      .star-rating__icon--line {
        display none
      }

      .icon svg path {
        fill #FFB800
      }
    }
  }

  &--readonly &__star {
    cursor default

    &:hover {
      transform none
    }
  }

  &__value {
    margin-left 8px
    font-size 0.875rem
    color var(--dark-light)
  }
}
</style>
