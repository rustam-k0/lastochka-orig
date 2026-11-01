<template>
  <div class="bulk-link-bar">
    <span class="bulk-link-bar__count">
      Категорий 1С: {{ oneCCount }} · Витрин: {{ showcaseCount }}
    </span>
    <span class="bulk-link-bar__hint">
      <template v-if="showcaseCount === 0">
        → перетащите/кликните по витринной категории или отметьте несколько для массовой привязки. Существующие связи сохраняются — добавление аддитивное.
      </template>
      <template v-else>
        → нажмите «Привязать», чтобы добавить все выбранные витрины каждой из выбранных категорий 1С.
      </template>
    </span>
    <button
      v-if="showcaseCount > 0"
      class="bulk-link-bar__link"
      :disabled="oneCCount === 0"
      @click="$emit('link')"
    >
      Привязать {{ oneCCount }}→{{ showcaseCount }}
    </button>
    <button class="bulk-link-bar__reset" @click="$emit('reset')">Сбросить</button>
  </div>
</template>

<script>
export default {
  name: "BulkLinkBar",
  props: {
    oneCCount: { type: Number, required: true },
    showcaseCount: { type: Number, default: 0 },
  },
  emits: ["reset", "link"],
};
</script>

<style lang="stylus" scoped>
.bulk-link-bar
  position fixed
  bottom 0
  left 0
  right 0
  background var(--dark, #333)
  color var(--white, #fff)
  padding 12px 24px
  display flex
  gap 16px
  align-items center
  z-index 100
  box-shadow 0 -2px 12px rgba(0, 0, 0, 0.15)

  &__count
    font-weight 600

  &__hint
    color var(--gray-light, #ccc)
    font-size 13px
    flex 1
    min-width 0

  &__link
    background var(--pink, #ff8c00)
    color var(--white, #fff)
    border none
    padding 8px 18px
    border-radius 4px
    cursor pointer
    font-weight 600
    transition opacity 0.15s

    &:hover:not(:disabled)
      opacity 0.9

    &:disabled
      opacity 0.4
      cursor not-allowed

  &__reset
    background transparent
    color var(--white, #fff)
    border 1px solid var(--white, #fff)
    padding 6px 14px
    border-radius 4px
    cursor pointer
    transition all 0.15s

    &:hover
      background var(--white, #fff)
      color var(--dark, #333)
</style>
