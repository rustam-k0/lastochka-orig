<template>
  <div
    class="checkbox"
    :class="{
      'checkbox--error': errors.length,
      'checkbox--focused': focused,
      'checkbox--active': modelValue || indeterminate,
      'checkbox--indeterminate': indeterminate,
      'checkbox--small': small,
      'checkbox--big': big,
      'checkbox--green': green && modelValue,
    }"
  >
    <label class="checkbox__field" @click.shift="$emit('update:shift:modelValue', modelValue)">
      <span class="checkbox__icon">
        <IconComponent name="subtract-line" v-if="indeterminate" />
        <IconComponent name="check-line" v-else-if="modelValue" />
      </span>
      <input
        @focus="focused = true"
        @blur="focused = false"
        @input="$emit('update:model-value', indeterminate ? false : $event.target.checked)"
        type="checkbox"
        :value="modelValue"
      />
      <slot></slot>
    </label>
    <div class="checkbox__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="checkbox__messages" v-if="messages.length">
      <span v-for="(message, i) in messages" :key="i">{{ message }}</span>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "CheckboxComponent",
  components: { IconComponent },
  emits: ["update:model-value", "update:shift:model-value"],
  props: {
    modelValue: Boolean,
    indeterminate: Boolean,
    small: Boolean,
    big: Boolean,
    green: Boolean,
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
.checkbox {
  display inline-grid
  grid-gap 5px

  &__icon {
    background: var(--white);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    display flex
    align-items center
    justify-content center
    width 18px
    height 18px
    transition .2s
    flex-shrink 0

    ^[0]--big & {
      width 20px
      height 20px
    }

    ^[0]--small & {
      width 16px
      height 16px
    }

    ^[0]--active & {
      background: var(--dark);
      border-color var(--dark)
    }

    ^[0]--green & {
      background var(--main)
      border-color var(--main)
    }

    ^[0]--focused & {
      box-shadow: 0 0 0 3px var(--dark-o5);
    }


  .icon {
      width 100%
      height 100%
      margin -1px

      svg path {
        fill var(--white)
      }
    }
  }

  &__field {
    display flex
    align-items center
    justify-content flex-start
    gap 3px
    cursor pointer
    font-weight 500
    font-size 0.813rem
    line-height 18px
    width: fit-content

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
