<template>
  <div class="time-range">
    <div class="time-range__header">Добавить диапазон</div>
    <div class="time-range__body" v-if="modelValue.length">
      <div class="time-range__items">
        <div class="time-range__item" v-for="(item, i) in modelValue" :key="i">
          <div class="time-range__item-inner">
            <div class="time-range__inputs">
              <div class="time-range__input">
                <span>с</span>
                <input
                  v-maska="'##:##'"
                  v-model="item.value.start"
                  @input="$emit('update:model-value', modelValue)"
                  type="text"
                  placeholder="00:00"
                />
              </div>
              <div class="time-range__input">
                <span>до</span>
                <input
                  v-maska="'##:##'"
                  v-model="item.value.end"
                  @input="$emit('update:model-value', modelValue)"
                  type="text"
                  placeholder="00:00"
                />
              </div>
            </div>
            <button @click="removeModelValue(i)" class="time-range__remove">
              <IconComponent name="close" />
            </button>
          </div>
          <div class="time-range__item-errors" v-if="item.errors && item.errors.length">
            <span v-for="(error, i) in item.errors" :key="i">{{ error }}</span>
          </div>
        </div>
      </div>
      <div class="time-range__errors" v-if="errors && errors.length">
        <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
      </div>
    </div>
    <div class="time-range__footer">
      <button type="button" @click="setModelValue" class="btn btn--sm time-range__add">
        <IconComponent name="add" />
        <span>Добавить диапазон</span>
      </button>
      <button type="button" class="btn btn--sm" @click.prevent="$emit('submit')">Сохранить</button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { maska } from "maska";

export default {
  name: "TimeRangeComponent",
  emits: ["update:model-value", "submit"],
  components: {
    IconComponent,
  },
  props: {
    modelValue: {
      type: Array,
      default() {
        return [];
      },
    },
    errors: {
      type: Array,
      default() {
        return [];
      },
    },
  },
  data: () => ({
    template: {
      value: {
        start: undefined,
        end: undefined,
      },
      errors: [],
    },
  }),
  methods: {
    setModelValue() {
      let array = this.modelValue;
      array.push(JSON.parse(JSON.stringify(this.template)));
      this.$emit("update:model-value", array);
    },
    removeModelValue(index) {
      let array = this.modelValue;
      if (array.length === 1) return;
      array.splice(index, 1);
      this.$emit("update:model-value", array);
    },
  },
  directives: {
    maska,
  },
};
</script>

<style lang="stylus">
.time-range {
  max-width 330px
  width 100%
  display inline-flex
  gap 14px
  flex-direction column
  padding 15px 20px
  background-color var(--white)
  box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
  border-radius: 5px;

  &__header {
    font-weight 700
    font-size 1.1250rem
    color var(--dark)
    line-height 28px
  }

  &__body {
    font-size 0.8750rem
    color var(--dark)
  }

  &__items {
    display flex
    flex-direction column
    align-items flex-start
    gap 0
  }

  &__remove {
    height: 40px;
    width 40px
    background var(--gray)
    border 1px solid var(--gray-dark)
    display: inline-flex;
    justify-content: center;
    align-items: center;
    border-radius 5px
    transition .2s

    &:active {
      transform scale(0.9)
    }

    .icon {
      width 24px
      height 24px

      path {
        fill var(--dark-light)
      }
    }
  }

  &__item {
    width 100%

    &-inner {
      display: flex;
      padding 6px 0
      justify-content: space-between;
    }
  }

  &__errors,
  &__item-errors {
    span {
      font-weight: 400;
      font-size: 0.625rem;
      line-height: 15px;
      display grid
      justify-content start
      grid-gap 3px
      opacity 1
      color var(--red)
      text-align left !important
    }
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
      max-width 71px
      width 100%
      text-align center
      outline none
      border-radius 5px
      height 40px
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

  &__footer {
    display flex
    gap 10px
    justify-content flex-start
  }
}
</style>
