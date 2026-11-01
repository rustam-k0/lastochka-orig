<template>
  <label
    :class="{
      'radio--disabled': disabled,
      'radio--checked': checked,
      'radio--error': error,
    }"
    class="radio"
  >
    <span class="radio__state"></span>
    <input
      :checked="checked"
      :disabled="disabled"
      type="checkbox"
      @change="$emit('change', $event.target.checked)"
    />
    <slot />
  </label>
</template>

<script>
export default {
  name: "RadioComponent",
  model: {
    prop: "checked",
    event: "change",
  },
  props: {
    error: Boolean,
    checked: Boolean,
    disabled: Boolean,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.radio {
  display flex
  gap 8px
  position relative
  cursor pointer
  align-items: center;
  text-align: left;
  user-select none
  font-size: 0.875em; // 14px
  line-height: 18px;

  input {
    absolute left top
    visibility hidden
  }

  &--error {
    color $red !important

    & ^[0]__state {
      border-color $red !important
    }
  }

  &--disabled {
    color $gray

    & ^[0]__state {
      border: 1px solid $gray;
    }

    &.radio--checked {
      & ^[0]__state {
        border: 1px solid $gray;

        &:after {
          background: $gray;
        }
      }
    }
  }

  &--checked:not(.radio--disabled) {
    color $dark

    & ^[0]__state {
      border-color $main_color

      &:after {
        background: $main_color;
      }
    }
  }

  &__state {
    width 16px
    height 16px
    background: $white;
    border: 1px solid $gray;
    box-sizing: border-box;
    border-radius: 10px;
    display flex
    align-items center
    flex-shrink 0
    justify-content center
    transition .2s
    position relative

    &:after {
      content: "";
      width 10px
      height 10px
      flex-shrink 0
      border-radius 100%
      background transparent
      transition .2s
    }
  }
}
</style>
