<template>
  <div class="store-create-table__list-wrapper">
    <div class="store-create-table__list">
      <button
        v-for="(day, i) in week"
        :key="i"
        class="btn btn--md"
        :class="{
          'btn--green': modelValue.includes(i + 1),
          'btn--white': !modelValue.includes(i + 1),
        }"
        @click="riseChecked(i + 1)"
      >
        {{ day }}
      </button>
    </div>
    <CheckboxComponent v-if="checkbox" @update:model-value="checkAll" v-model="active" green big
      >Ежедневно</CheckboxComponent
    >
  </div>
</template>

<script>
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";

export default {
  name: "WorkDaysComponent",
  components: { CheckboxComponent },
  emits: ["update:model-value"],
  props: {
    modelValue: {
      type: Array,
      default: () => [],
    },
    checkbox: {
      type: Boolean,
      default: false,
    },
  },

  mounted() {
    if (this.modelValue.length === 7) {
      this.active = true;
    }
  },

  data() {
    return {
      week: ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"],
      active: false,
    };
  },

  methods: {
    checkAll() {
      this.$emit("update:model-value", [1, 2, 3, 4, 5, 6, 7]);
    },
    riseChecked(i) {
      this.active = false;
      let current = [...this.modelValue];
      let indexOf = current.indexOf(i);
      if (current.includes(i)) {
        current.splice(indexOf, 1);
      } else {
        current = [...current, i];
      }
      this.$emit("update:model-value", current);
    },
  },
  watch: {
    modelValue(value) {
      if (value.length === 7) {
        this.active = true;
      }
    },
  },
  // computed: {
  //   correctModelValue() {
  //     return this.modelValue.map((item) => item.day_of_week)
  //   }
  // }
};
</script>

<style lang="stylus">
.store-create-table {

  &__list-wrapper {
    display flex
    flex-direction column
    gap 8px
  }

  &__list {
    display flex
    gap 6px

    & .btn--green {
      background color(--green-light)
    }
    //span:not(.status--green) {
    //  border 1px solid var(--white-2)
    //}
  }
}
</style>
