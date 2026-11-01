<template>
  <ul class="preview-tabs">
    <li
      v-for="(item, i) in items"
      :key="i"
      class="preview-tabs__item btn btn--md btn--green"
      :class="{ 'preview-tabs__item--active': i === activeItem }"
      @click="$emit('change-tabs', i)"
    >
      {{ item }}
    </li>
  </ul>
</template>

<script>
export default {
  name: "PreviewTabs",

  props: {
    items: {
      type: Array,
      default: () => [],
    },
    activeItem: {
      type: Number,
      default: 1,
    },
  },
};
</script>

<style lang="stylus">
.preview-tabs {
  background var(--white)
  display flex
  gap 8px

  &__item--active {
    background var(--main)
    color var(--white)
  }
}
</style>
