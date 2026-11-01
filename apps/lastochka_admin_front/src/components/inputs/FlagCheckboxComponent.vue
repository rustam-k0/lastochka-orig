<template>
  <div
    class="f-checkbox"
    :class="{
      'f-checkbox--error': errors.length,
      'f-checkbox--focused': focused,
      'f-checkbox--active': modelValue,
    }"
  >
    <label class="f-checkbox__field" @click.shift="$emit('update:shift:model-value', modelValue)">
      <span class="f-checkbox__body" :class="{ 'f-checkbox--green': green }">
        <span class="f-checkbox__flag"></span>
      </span>
      <input
        ref="flagCheckbox"
        @focus="focused = true"
        @blur="focused = false"
        @input="$emit('update:model-value', $event.target.checked)"
        type="checkbox"
        :disabled="disabled"
        :checked="modelValue"
      />
      <slot></slot>
    </label>
    <div class="f-checkbox__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="f-checkbox__messages" v-if="messages.length">
      <span v-for="(message, i) in messages" :key="i">{{ message }}</span>
    </div>
  </div>
</template>

<script>
export default {
  name: "FlagCheckboxComponent",
  emits: ["update:model-value", "update:shift:model-value"],
  mounted() {
    // this.$refs.flagCheckbox.focus();
  },
  props: {
    disabled: {
      type: Boolean,
    },
    green: Boolean,
    modelValue: Boolean,
    messages: {
      type: Array,
      default: () => [],
    },
    errors: {
      type: Array,
      default: () => [],
    },
  },
  data: () => ({
    focused: false,
  }),
};
</script>

<style lang="stylus">
.f-checkbox {
  display inline-grid
  grid-gap 5px

  &__field {
    display flex
    align-items center
    //justify-content center
    gap 4px
    cursor pointer
    font-weight 500
    font-size 13px
    line-height 18px
    color #373737

    input {
      appearance none
      border none
      background none
      outline none
      overflow hidden
      height 0
      width 0
    }
  }

  &__body {
    position relative
    display flex
    align-items center
    justify-content flex-start
    background transparent
    padding 3px
    width 40px
    height 24px
    border 1px solid #D1D1D1
    border-radius 999px
    transition .2s

    ^[0]--active & {
      background #222128
      border-color #D1D1D1
    }
  }

  &--active {

    & ^[0]--green {
      background #5AB033
      border-color #D1D1D1
    }
  }

  &__flag {
    position absolute
    transform translateX(0px)
    left 3px
    display flex
    width 16px
    height 16px
    border-radius 999px
    background #D9D9D9
    transition .2s

    ^[0]--active & {
      left 19px
      background #FFFFFF
    }
  }

  &__errors,
  &__messages {
    font-weight: 400;
    font-size: 0.75rem;
    line-height: 15px;
    color: var(--dark);
    opacity: 0.4;
    display grid
    grid-gap 3px
  }

  &__errors {
    opacity 1
    color var(--red)
  }
}
</style>
