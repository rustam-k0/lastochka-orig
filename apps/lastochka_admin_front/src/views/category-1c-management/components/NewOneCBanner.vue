<template>
  <div v-if="count > 0" class="new-1c-banner">
    <span class="new-1c-banner__icon">⚠</span>
    <span class="new-1c-banner__text">Новых категорий из 1С: {{ count }}</span>
    <button class="new-1c-banner__btn" @click="$emit('show')">Показать</button>
  </div>
</template>

<script>
export default {
  name: "NewOneCBanner",
  props: {
    count: { type: Number, required: true },
  },
  emits: ["show"],
};
</script>

<style lang="stylus" scoped>
.new-1c-banner
  background var(--yellow-light, #fff3cd)
  color var(--yellow-dark, #856404)
  padding 8px 16px
  border-radius 4px
  display inline-flex
  gap 12px
  align-items center
  border 1px solid var(--yellow, #ffeeba)

  &__icon
    font-size 16px

  &__text
    font-size 13px

  &__btn
    background transparent
    color var(--yellow-dark, #856404)
    border 1px solid var(--yellow-dark, #856404)
    padding 4px 12px
    border-radius 4px
    cursor pointer
    font-size 12px
    transition all 0.15s

    &:hover
      background var(--yellow-dark, #856404)
      color var(--white, #fff)
</style>
