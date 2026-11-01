<template>
  <div class="interval">
    <div class="interval__header">
      <IconComponent name="clock" />
      <span>Изменить интервал</span>
    </div>
    <div class="interval__body">
      <input
        v-maska="'###'"
        class="interval__input"
        type="text"
        :value="modelValue"
        @input="input"
        :placeholder="placeholder"
      />
      <label>минут</label>
    </div>
    <div class="interval__footer">
      <div
        v-for="(tab, i) in tabs"
        :key="i"
        @click="$emit('update:model-value', tab)"
        :class="{ 'interval__footer_item--active': modelValue === tab }"
        class="interval__footer_item"
      >
        {{ tab }}
      </div>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { maska } from "maska";

export default {
  name: "IntervalComponent",
  emits: ["update:model-value"],
  props: {
    modelValue: Number,
    maxValue: Number,
    placeholder: {
      type: String,
      default: "",
    },
  },
  data: () => ({
    debounceInterval: null,
    tabs: [15, 30, 45, 60],
  }),
  methods: {
    input(event) {
      if (this.debounceInterval) {
        clearInterval(this.debounceInterval);
      }
      this.debounceInterval = setInterval(() => {
        let value = 5;
        if (event.target.value) {
          value = event.target.value;
          if (event.target.value >= this.maxValue) {
            value = this.maxValue;
          }
          if (event.target.value <= 5) {
            value = 5;
          }
        }
        this.$emit("update:model-value", value);
      }, 300);
    },
  },
  components: {
    IconComponent,
  },
  directives: {
    maska,
  },
};
</script>

<style lang="stylus">
.interval {
  display inline-flex
  flex-direction column
  padding 15px
  border 1px solid var(--gray-dark)
  border-radius 10px
  color var(--dark)
  box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
  background-color var(--white)

  &__header {
    font-size: 0.875rem
    font-weight: 500;
    line-height: 20px;
    color: var(--dark);
    display flex
    justify-content flex-start
    align-items center
    gap 5px

    .icon {
      width 16px
      height 16px

      path {
        fill var(--dark-light)
      }
    }
  }

  &__body {
    display flex
    gap 10px
    justify-content flex-start
    align-items center
    font-size: 0.875rem
    margin-top 10px

    label {
      font-weight: 500;
      font-size: 0.875rem;
      line-height: 20px;
      color var(--dark-light)
    }
  }

  &__input {
    max-width 130px
    width 100%
    text-align center
    outline none
    border-radius 5px
    color var(--dark)
    padding 5px 0
    border 1px solid var(--gray-dark)
    //&:focus {
    //  border: 1px solid var(--dark);
    //  box-shadow: 0 0 0 3px var(--main-o15);
    //}
  }

  &__footer {
    display flex
    flex-wrap wrap
    gap 5px
    font-weight: 500;
    font-size 0.75rem
    margin-top 10px
    line-height: 15px;
    color: var(--dark);

    &_item {
      padding 5px 10px
      border-radius 5px
      border 1px solid var(--gray-dark)
      background-color var(--gray)
      cursor pointer

      &--active {
        background-color var(--main)
        border 1px solid var(--main)
        color var(--white)
      }
    }
  }
}
</style>
