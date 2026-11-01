<template>
  <ul class="select-list">
    <template v-if="filteredOptions.length">
      <li v-for="(o, i) in filteredOptions" :key="i" class="select-list__item">
        <CheckboxComponent @update:model-value="toggle(o)" :modelValue="isChecked(o)" v-if="multiple">
          <span>{{ o[labelName] }}</span>
        </CheckboxComponent>
        <button type="button" @click.prevent="toggle(o)" v-else>{{ o[labelName] }}</button>
      </li>
    </template>
    <li v-else class="select-list__empty">Пусто</li>
  </ul>
</template>

<script>
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";

export default {
  name: "SelectActionListComponent",
  components: { CheckboxComponent },
  props: {
    labelName: {
      type: String,
      default: "title",
    },
    query: {
      type: String,
      default: "",
    },
    modelValue: [Array, Object],
    options: { type: Array, required: true },
    multiple: Boolean,
  },
  computed: {
    filteredOptions() {
      return this.options.filter((o) => o[this.labelName].toLowerCase().includes(this.query.toLowerCase()));
    },
  },
  methods: {
    isChecked(option) {
      if (this.multiple) {
        return this.modelValue.map((s) => JSON.stringify(s)).includes(JSON.stringify(option));
      } else {
        return JSON.stringify(option) === JSON.stringify(this.modelValue);
      }
    },
    toggle(o) {
      if (this.multiple) {
        let array = this.modelValue;
        if (this.isChecked(o)) {
          let indexOfSelected = this.modelValue.findIndex((v) => JSON.stringify(v) === JSON.stringify(o));
          array.splice(indexOfSelected, 1);
        } else {
          array.push(o);
        }
        this.$emit("update:model-value", array);
      } else {
        this.$emit("update:model-value", o);
      }
    },
  },
};
</script>

<style lang="stylus">
.select-list {
  background: var(--white);
  border: 1px solid var(--gray-dark);
  border-radius: 5px;
  display flex
  flex-direction column
  align-items start
  overflow hidden

  &__item {
    display inline-flex
    align-items center
    justify-content stretch
    width 100%
    height 100%

    .checkbox,
    button {
      padding 5px 10px
      transition background .2s
      background none
      border none
      width 100%
      font-weight: 500;
      font-size: 0.875em;
      line-height: 16px;
      color: var(--dark);
      text-align left
      min-height 18px

      &:hover {
        background var(--main-o15)
      }

      &__field {
        gap 5px
        padding 5px 10px
      }
    }

    .checkbox {
      padding 0
    }
  }

  &__empty {
    font-weight: 500;
    font-size: 0.875em;
    line-height: 16px;
    color: var(--dark);
    padding 5px 10px
  }
}
</style>
