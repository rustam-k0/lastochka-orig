<template>
  <div
    class="array"
    :class="{
      'array--error': errors && errors.length,
      'array--static': !template,
    }"
  >
    <span class="array__title">{{ title }}</span>
    <div class="array__list">
      <div v-for="(item, i) in modelValue" :key="i" class="array__item">
        <slot :i="i" :item="item"></slot>
        <template v-if="justRemoveBtn">
          <div class="array__item-actions">
            <button class="btn btn--md btn--danger" type="button" @click.prevent="remove(i)">
              <IconComponent name="delete-bin-line" />
            </button>
          </div>
        </template>
        <template v-else>
          <template v-if="template && !hideAddButton">
            <div class="array__item-actions">
              <button
                v-if="modelValue.length - 1 === i"
                class="btn btn--md btn--green"
                type="button"
                @click.prevent="add"
              >
                <IconComponent name="add-circle-line" />
              </button>
              <button v-else class="btn btn--md btn--danger" type="button" @click.prevent="remove(i)">
                <IconComponent name="delete-bin-line" />
              </button>
            </div>
          </template>
          <template v-if="template && hideAddButton">
            <div v-if="modelValue.length - 1 !== i" class="array__item-actions">
              <button class="btn btn--md btn--danger" type="button" @click.prevent="remove(i)">
                <IconComponent name="delete-bin-line" />
              </button>
            </div>
          </template>
        </template>
        <div class="array__item-errors" v-if="item.errors && item.errors.length">
          <span v-for="(error, i) in item.errors" :key="i">{{ error }}</span>
        </div>
      </div>
    </div>
    <div class="array__errors" v-if="errors && errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <slot name="footer"></slot>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "ArrayComponent",
  components: { IconComponent },
  emits: ["update:modelValue"],
  props: {
    justRemoveBtn: {
      type: Boolean,
      default: false,
    },
    title: {
      type: String,
      default: "",
    },
    modelValue: {
      type: Array,
      default() {
        return [];
      },
    },
    errors: {
      type: Array,
      default: () => [],
    },
    template: Object,
    hideAddButton: {
      type: Boolean,
      default: false,
    },
  },
  methods: {
    remove(i) {
      if (confirm("Вы уверены?")) {
        let value = this.modelValue;
        value.splice(i, 1);
        // if (!value.length) {
        //   this.add();
        // }
        this.$emit("update:modelValue", value);
      }
    },
    add() {
      let value = this.modelValue;
      value.push(JSON.parse(JSON.stringify(this.template)));
      this.$emit("update:modelValue", value);
    },
  },
};
</script>

<style lang="stylus">
.array {
  display grid
  //grid-gap 10px

  &__title {
    font-weight: 500;
    font-size: 1rem;
    line-height: 26px;
    color: var(--dark-light);
  }

  &__list {
    border-radius 10px
    display grid
    grid-gap 16px

    ^[0]--error & {
      border-color var(--red)
    }
  }

  &__item {
    display flex
    gap 16px

    ^[0]--static & {
      grid-template-columns 1fr
    }

  }

  &__item-actions {
    display: flex;
    align-items: flex-end;



    & .btn--danger {
      width 48px
      height 48px
    }
    .btn:not(.btn--danger) {
      padding 0
      width 48px
      height 48px
      background var(--green-50)

      &:hover {
        background var(--green-hover)

        .icon svg path {
          fill var(--white)
        }
      }

      .icon svg path {
        fill var(--green-700)
      }
    }
  }

  &__errors,
  &__item-errors {
    font-weight: 400;
    font-size: 0.75rem;
    line-height: 15px;
    opacity 1
    color var(--red)
    display grid
    grid-gap 3px
  }
}
</style>
