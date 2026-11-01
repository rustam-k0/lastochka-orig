<template>
  <div class="select__list">
    <div class="select__scroll">
      <div
        v-for="(o, i) in options"
        :key="i"
        :class="{
          'select__item--single': !multiple,
          'select__item--checked': isChecked(o),
        }"
        class="select__item"
        @click="multiple ? false : $emit('select', o)"
      >
        <CheckboxComponent v-if="multiple" :checked="isChecked(o)" @change="$emit('select', o)">
          <span>{{ getName(o) }}</span>
        </CheckboxComponent>
        <template v-else>{{ getName(o) }}</template>
      </div>
      <div v-if="!options.length" class="select__item select__item--single">Ничего не найдено</div>
    </div>
    <a v-if="multiple && clearable" class="select__clean-list" href="#" @click.prevent="$emit('clean')">
      Очистить
    </a>
  </div>
</template>

<script>
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";

export default {
  name: "SelectListComponent",
  components: { CheckboxComponent },
  props: {
    labelName: {
      type: String,
      default() {
        return "name";
      },
    },
    selected: { type: [Array, Number, Object] },
    options: { type: Array, required: true },
    multiple: Boolean,
    clearable: Boolean,
  },
  methods: {
    isChecked(option) {
      if (this.multiple && this.selected && this.selected instanceof Array) {
        return this.selected.map((s) => JSON.stringify(s)).includes(JSON.stringify(option));
      }
      if (!this.multiple) {
        return JSON.stringify(option) === JSON.stringify(this.selected);
      }
      return false;
    },
    getName(option) {
      if (this.labelName) {
        return option[this.labelName];
      }
      return option;
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.select__list {
  absolute bottom left
  width calc(100% + 2px)
  transform translateY(calc(100% - 1px))
  z-index 10
  background $white
  border 1px solid $gray1
  border-radius 0 0 10px 10px
  box-sizing: border-box;
  margin -1px
  box-shadow: 0 0 8px rgba($black, 0.1);
  overflow hidden
  overflow-y auto
  max-height: 200px;
  display grid
  grid-template-rows 1fr auto
}

.select__scroll {
  display flex
  flex-direction column
  flex-shrink 0
  overflow-y auto
}

.select__clean-list {
  border-top 1px solid $gray1
  padding 8px 12px
  color $dark
  cursor pointer
  text-decoration none

  &:hover {
    color $dark
    border-color $gray1
  }
}

.select__item {
  width 100%
  text-align left
  cursor pointer
  transition .2s
  outline none
  color $dark
  text-decoration none
  display flex
  flex-shrink 0

  &--single {
    padding 8px 12px
  }

  .check {
    padding 8px 12px
    width 100%
  }

  &:focus
  &--checked
  &:hover {
    background $gray1
    color $dark
  }
}
</style>
