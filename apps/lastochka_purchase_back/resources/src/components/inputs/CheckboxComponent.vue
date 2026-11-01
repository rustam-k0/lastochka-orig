<template>
  <label
    :class="{
      'check--disabled': disabled,
      'check--error': error,
      'check--checked': checked,
      'check--indeterminate': indeterminate,
    }"
    class="check"
  >
    <span class="check__state">
      <Icon v-if="state" category="system" :name="state" />
    </span>
    <input
      :checked="checked"
      :disabled="disabled"
      :indeterminate="indeterminate"
      type="checkbox"
      @change="$emit('change', $event.target.checked)"
    />
    <slot />
  </label>
</template>

<script>
import Icon from "@/components/IconComponent.vue";

export default {
  name: "CheckboxComponent",
  components: { Icon },
  model: {
    prop: "checked",
    event: "change",
  },
  props: {
    error: Boolean,
    checked: Boolean,
    disabled: Boolean,
    indeterminate: Boolean,
  },
  computed: {
    state() {
      if (this.indeterminate) {
        return "indeterminate";
      }
      return "check-line";
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.check {
  display flex
  gap 8px
  position relative
  cursor pointer
  align-items: center;
  text-align: left;
  user-select none
  font-size: 0.8750em; // 14px
  line-height: 17px;

  input {
    absolute left top
    visibility hidden
  }

  &--error {
    color $red !important

    & .check__state {
      border-color $red !important
    }
  }

  &--disabled {
    color $gray1

    & .check__state {
      background: $gray1;
      border: 1px solid $gray1;
    }

    &.check--indeterminate
    &.check--checked {
      & .check__state {
        background: $gray1;
        border: 1px solid $gray1;
      }

      svg path {
        fill $white
      }
    }
  }

  &--indeterminate:not(&.check--disabled)
  &--checked:not(&.check--disabled) {
    color $black

    & .check__state {
      background $main_color
      border-color $main_color

      svg path {
        fill $white
      }
    }
  }

  &__state {
    width 16px
    height 16px
    background: $white;
    border: 1px solid $gray1;
    box-sizing: border-box;
    border-radius: 3px;
    display flex
    align-items center
    justify-content center
    transition .2s

    .icon {
      width 16px
      height 16px
      display flex
      align-items center
      justify-content center

      svg {
        transition .2s
        fill transparent
      }
    }
  }
}
</style>
