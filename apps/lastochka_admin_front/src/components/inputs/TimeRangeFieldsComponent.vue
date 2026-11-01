<template>
  <div class="time-range-fields">
    <label class="time-range-fields__title" v-if="title">
      {{ title }}
      <span v-if="required">*</span>
    </label>
    <div class="time-range-fields__body">
      <div class="time-range-fields__inputs">
        <div class="time-range-fields__input">
          <span>с</span>
          <input
            v-maska="'##:##'"
            :value="modelValue.start"
            @input="$emit('update:model-value', modelValue)"
            type="text"
            placeholder="00:00"
          />
        </div>
        <div class="time-range-fields__input">
          <span>до</span>
          <input
            v-maska="'##:##'"
            :value="modelValue.end"
            @input="$emit('update:model-value', modelValue)"
            type="text"
            placeholder="00:00"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { maska } from "maska";

export default {
  name: "TimeRangeComponent",
  emits: ["update:model-value"],
  props: {
    modelValue: {
      type: Object,
      default() {
        return { start: "", end: "" };
      },
    },
    title: {
      type: String,
      default: "",
    },
    required: Boolean,
  },
  directives: {
    maska,
  },
};
</script>

<style lang="stylus">
.time-range-fields {
  max-width 330px
  width 100%
  display inline-flex
  gap 5px
  flex-direction column
  background-color var(--white)
  border-radius: 5px;

  &__title {
    font-weight: 700;
    font-size: 0.865rem;
    line-height: 24px;
    color: var(--dark);

    ^[0]--error & {
      color var(--red)
    }

    > span {
      color: var(--red);
    }
  }

  &__body {
    font-size 0.8750rem
    color var(--dark)
    display flex
    flex-direction column
    align-items flex-start
    gap 0
  }

  &__inputs {
    display inline-flex
    align-items center
    gap 10px
  }

  &__input {
    display flex
    align-items center
    gap 5px

    input {
      max-width 140px
      width 100%
      text-align center
      outline none
      border-radius 5px
      height 60px
      padding 0 15px
      font-size 0.8750rem
      line-height 21px
      background-color var(--gray)
      color var(--dark)
      border 1px solid var(--gray-dark)

      &::placeholder {
        color: var(--dark-light);
      }

      &:focus {
        border: 1px solid var(--dark);
        box-shadow: 0 0 0 3px var(--main-o15);
      }
    }
  }
}
</style>
