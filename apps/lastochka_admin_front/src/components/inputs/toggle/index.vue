<template>
  <div class="toggle" ref="root">
    <label
      class="toggle__item"
      :class="{ 'toggle__item--active': modelValue === option.value }"
      v-for="(option, index) in options"
      :key="index"
    >
      <input
        type="radio"
        :name="name"
        :value="option.value"
        :checked="modelValue === option.value"
        @input="handleChecked(option.value)"
      />
      <span>
        {{ option.title }}
      </span>
    </label>
  </div>
</template>

<script>
export default {
  name: "ToggleRadio",
  props: {
    modelValue: {
      type: [String, Number],
    },
    name: [String],
    options: {
      type: Array,
      default: () => [],
    },
  },
  methods: {
    handleChecked(e) {
      this.$emit("update:model-value", e);
    },
  },
};
</script>

<style lang="stylus">
.toggle {
  display grid
  gap 24px

  &__item {
    cursor pointer
    display inline-flex
    position relative
    align-items center
    gap 8px
    width fit-content

    &--active&:nth-child(even) span {
      border-color var(--yellow)
    }

    &--active&:nth-child(odd) span {
      border-color var(--green)
    }

    &--active&:nth-child(3n) span {
      border-color var(--red)
    }

    input {
      width 20px
      height 20px
    }

    input[type='radio'] {
      position: relative;
      height: 22px;
      width: 22px;
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
      outline: none;
    }

    input[type='radio']::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      transform: translate(-50%, -50%);
      background-color: white;
      border: 1px solid var(--gray-dark-3);
    }

    input[type='radio']:checked::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background-color: var(--black);
      transform: translate(-50%, -50%);
      visibility: visible;
    }

    > span {
      border-radius 100%
      transition .2s
      font-weight: 500;
      font-size: 0.813rem
      line-height: 140%;
      color: var(--dark)
    }
  }
}
</style>
