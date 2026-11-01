<template>
  <div class="table-settings">
    <span class="table-settings__header">Выберите поля</span>
    <div class="table-settings__body table-settings__body--col-2" v-if="types && types.length">
      <div class="table-settings__list" v-for="(type, i) in types" :key="i">
        <span class="table-settings__list-header">{{ type.title }}</span>
        <div class="table-settings__list-body">
          <CheckboxComponent
            v-for="(o, i) in modelValue.filter((f) => f.for && f.for === type.id && !f.system)"
            :key="i"
            @update:model-value="toggle(o)"
            :modelValue="isChecked(o)"
          >
            <span>{{ o.title }}</span>
          </CheckboxComponent>
        </div>
      </div>
    </div>
    <div class="table-settings__body" v-else>
      <div class="table-settings__list-body">
        <CheckboxComponent
          v-for="(o, i) in modelValue.filter((f) => !f.system)"
          :key="i"
          @update:model-value="toggle(o)"
          :modelValue="isChecked(o)"
        >
          <span>{{ o.title }}</span>
        </CheckboxComponent>
      </div>
    </div>
  </div>
</template>
<script>
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";

export default {
  name: "TableColSelector",
  emits: ["update:model-value"],
  props: {
    modelValue: Array,
    types: Array,
  },
  methods: {
    toggle(option) {
      let array = this.modelValue;
      let optionIndex = null;
      array.find((v, index) => {
        if (v.field === option.field) {
          optionIndex = index;
          return true;
        }
        return false;
      }).show = !array[optionIndex].show;
      this.$emit("update:model-value", array);
    },
    isChecked(option) {
      return this.modelValue.find((s) => s.field === option.field).show;
    },
  },
  components: { CheckboxComponent },
};
</script>

<style lang="stylus">
.table-settings {
  background: var(--white);
  box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
  border-radius: 10px 0 10px 10px
  padding 20px
  max-height 250px
  overflow auto
  display grid
  grid-gap 20px
  scrollbar-width thin
  scrollbar-color rgba(0, 0, 0, 0.3) transparent

  &::-webkit-scrollbar {
    width 8px
    height 8px
  }

  &::-webkit-scrollbar-track {
    background transparent
  }

  &::-webkit-scrollbar-thumb {
    background-clip: padding-box;
    background-color rgba(0, 0, 0, 0.3)
    border-radius 3px
    border none
  }

  &__header {
    font-weight: 700;
    font-size: 1.125rem;
    line-height: 28px;
    color: var(--dark);
  }

  &__body {
    display grid

    &--col-2 {
      grid-template-columns repeat(2, 1fr)
      grid-gap 20px
    }
  }

  &__list {
    display grid
    grid-gap 10px
  }

  &__list-header {
    font-weight: 700;
    font-size: 0.875rem;
    line-height: 28px;
    width 100%
    color: var(--dark);
  }

  &__list-body {
    display grid
    grid-gap 10px
    width 100%

    .checkbox {
      font-weight: 500;
      font-size: 0.875rem;
      line-height: 22px;
      color: var(--dark);
    }
  }
}
</style>
