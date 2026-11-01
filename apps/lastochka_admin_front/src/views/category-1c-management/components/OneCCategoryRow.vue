<template>
  <div>
    <div
      class="one-c-row"
      :class="{
        'one-c-row--disabled': row.is_source_deleted,
        'one-c-row--muted': !isMatching,
        'one-c-row--branch': hasChildren,
      }"
      :style="{ paddingLeft: 12 + level * 20 + 'px' }"
    >
      <button
        v-if="hasChildren"
        type="button"
        class="one-c-row__toggle"
        :title="expanded ? 'Свернуть' : 'Развернуть'"
        @click="expanded = !expanded"
      >
        {{ expanded ? "▾" : "▸" }}
      </button>
      <span v-else class="one-c-row__toggle one-c-row__toggle--placeholder"></span>

      <input
        type="checkbox"
        class="one-c-row__checkbox"
        :checked="selected"
        :disabled="row.is_source_deleted || !row.is_leaf || !isMatching"
        @change="$emit('toggle', row.id)"
      />

      <div class="one-c-row__info">
        <div class="one-c-row__name">{{ row.name }}</div>
        <div v-if="hasChildren" class="one-c-row__meta">
          <span>{{ row.children.length }} подкат.</span>
        </div>
        <div
          v-if="row.is_linked && row.showcase_categories && row.showcase_categories.length"
          class="onec-category__showcases"
        >
          <span
            v-for="sc in row.showcase_categories"
            :key="sc.id"
            class="onec-category__chip"
          >
            {{ sc.title }}
            <button
              type="button"
              class="onec-category__chip-unlink"
              :title="`Отвязать «${sc.title}»`"
              @click.stop="$emit('unlink-showcase', { category_1c_id: row.id, showcase_category_id: sc.id })"
            >
              ×
            </button>
          </span>
        </div>
      </div>

      <div class="one-c-row__badges">
        <span v-if="row.is_new" class="badge badge--new">новая</span>
        <span v-if="row.is_linked" class="badge badge--linked">✓ привязана</span>
        <span v-if="row.is_source_deleted" class="badge badge--deleted">удалена в 1С</span>
      </div>

      <button
        v-if="row.is_linked && !row.is_source_deleted"
        class="one-c-row__unlink"
        title="Отвязать от витринной категории"
        @click="$emit('unlink', row.id)"
      >
        ×
      </button>
    </div>

    <div v-if="hasChildren && expanded" class="one-c-row__children">
      <OneCCategoryRow
        v-for="child in row.children"
        :key="child.id"
        :row="child"
        :level="level + 1"
        :selected-ids="selectedIds"
        :matching-ids="matchingIds"
        @toggle="$emit('toggle', $event)"
        @unlink="$emit('unlink', $event)"
        @unlink-showcase="$emit('unlink-showcase', $event)"
      />
    </div>
  </div>
</template>

<script>
export default {
  name: "OneCCategoryRow",
  props: {
    row: { type: Object, required: true },
    level: { type: Number, default: 0 },
    selectedIds: { type: Array, default: () => [] },
    matchingIds: { type: Object, default: () => new Set() },
  },
  emits: ["toggle", "unlink", "unlink-showcase"],
  data() {
    return {
      expanded: this.level < 1,
    };
  },
  computed: {
    hasChildren() {
      return Array.isArray(this.row.children) && this.row.children.length > 0;
    },
    selected() {
      return this.selectedIds.includes(this.row.id);
    },
    isMatching() {
      return this.matchingIds.has(this.row.id);
    },
    hasMetaLine() {
      return this.hasChildren || (this.row.is_linked && this.row.showcase_categories?.length > 0);
    },
  },
};
</script>

<style lang="stylus" scoped>
.one-c-row
  display flex
  gap 8px
  align-items center
  padding 8px 12px
  border 1px solid var(--gray-dark, #e0e0e0)
  border-radius 6px
  background var(--white, #fff)
  transition border-color 0.15s

  &:hover
    border-color var(--pink, #ff8c00)

  &--disabled
    opacity 0.5

  &--muted
    opacity 0.55
    background var(--gray-50, #f7f7f7)

  &--branch
    background var(--gray-50, #fafafa)
    font-weight 500

  &__toggle
    width 18px
    height 18px
    background transparent
    border none
    cursor pointer
    color var(--dark-light, #888)
    font-size 12px
    padding 0
    flex-shrink 0
    display flex
    align-items center
    justify-content center

    &--placeholder
      cursor default
      visibility hidden

  &__checkbox
    width 16px
    height 16px
    cursor pointer
    flex-shrink 0

    &:disabled
      cursor not-allowed

  &__info
    flex 1
    min-width 0

  &__name
    font-weight inherit
    color var(--dark, #222)
    word-break break-word

  &__meta
    font-size 11px
    color var(--dark-light, #888)
    display flex
    flex-wrap wrap
    gap 4px
    margin-top 2px
    font-weight normal

  &__dot
    color var(--gray-light, #ccc)

  &__linked-to
    color var(--green, #28a745)

  &__badges
    display flex
    gap 6px
    flex-shrink 0

  &__unlink
    background transparent
    border 1px solid var(--gray-dark, #e0e0e0)
    color var(--dark-light, #888)
    width 24px
    height 24px
    border-radius 50%
    cursor pointer
    font-size 16px
    line-height 1
    display flex
    align-items center
    justify-content center
    flex-shrink 0
    transition all 0.15s

    &:hover
      background var(--red, #dc3545)
      color var(--white, #fff)
      border-color var(--red, #dc3545)

  &__children
    display flex
    flex-direction column
    gap 4px
    margin-top 4px

.onec-category__showcases
  display flex
  flex-wrap wrap
  gap 4px
  margin-top 4px

.onec-category__chip
  display inline-flex
  align-items center
  gap 4px
  padding 2px 8px
  background #eef3fa
  border-radius 12px
  font-size 12px
  color var(--dark, #444)

.onec-category__chip-unlink
  background transparent
  border 0
  padding 0
  cursor pointer
  font-size 14px
  line-height 1
  color #678

  &:hover
    color #d33

.badge
  padding 2px 8px
  border-radius 4px
  font-size 11px
  white-space nowrap

  &--new
    background var(--orange, #ff8c00)
    color var(--white, #fff)

  &--linked
    background var(--green, #28a745)
    color var(--white, #fff)

  &--deleted
    background var(--gray, #888)
    color var(--white, #fff)
</style>
