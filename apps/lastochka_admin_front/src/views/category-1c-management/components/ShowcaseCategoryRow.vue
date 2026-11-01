<template>
  <div>
    <div
      class="showcase-row"
      :class="{
        'showcase-row--leaf': isLeaf,
        'showcase-row--clickable': isLeaf && hasSelection,
        'showcase-row--branch': !isLeaf,
      }"
      :style="{ paddingLeft: 12 + level * 20 + 'px' }"
      @click="onClick"
    >
      <button
        v-if="hasChildren"
        type="button"
        class="showcase-row__toggle"
        :title="expanded ? 'Свернуть' : 'Развернуть'"
        @click.stop="expanded = !expanded"
      >
        {{ expanded ? "▾" : "▸" }}
      </button>
      <span v-else class="showcase-row__toggle showcase-row__toggle--placeholder"></span>

      <input
        v-if="isLeaf"
        type="checkbox"
        class="showcase-row__checkbox"
        :checked="selected"
        :title="selected ? 'Снять выбор' : 'Выбрать витрину'"
        @click.stop
        @change="$emit('toggle-select', row.id)"
      />

      <div class="showcase-row__info">
        <div class="showcase-row__title">{{ row.title }}</div>
        <div v-if="hasChildren" class="showcase-row__meta">
          {{ row.children.length }} {{ pluralizeSubcat(row.children.length) }}
        </div>
      </div>

      <div class="showcase-row__badges">
        <span v-if="row.category_1c_mappings_count" class="badge badge--sources">
          {{ row.category_1c_mappings_count }} {{ pluralizeSources(row.category_1c_mappings_count) }} 1С
        </span>
        <span v-if="row.has_orphan_1c_sources" class="badge badge--warn">требует внимания</span>
      </div>
    </div>

    <div v-if="hasChildren && expanded" class="showcase-row__children">
      <ShowcaseCategoryRow
        v-for="child in row.children"
        :key="child.id"
        :row="child"
        :level="level + 1"
        :has-selection="hasSelection"
        :selected-ids="selectedIds"
        @link="$emit('link', $event)"
        @toggle-select="$emit('toggle-select', $event)"
      />
    </div>
  </div>
</template>

<script>
export default {
  name: "ShowcaseCategoryRow",
  props: {
    row: { type: Object, required: true },
    level: { type: Number, default: 0 },
    hasSelection: { type: Boolean, default: false },
    selectedIds: { type: Array, default: () => [] },
  },
  emits: ["link", "toggle-select"],
  data() {
    return {
      expanded: this.level < 1,
    };
  },
  computed: {
    hasChildren() {
      return Array.isArray(this.row.children) && this.row.children.length > 0;
    },
    isLeaf() {
      return !this.hasChildren;
    },
    selected() {
      return this.selectedIds.includes(this.row.id);
    },
  },
  methods: {
    onClick() {
      if (this.isLeaf && this.hasSelection) {
        this.$emit("link", { id: this.row.id, title: this.row.title });
      }
    },
    pluralizeSubcat(n) {
      const mod10 = n % 10;
      const mod100 = n % 100;
      if (mod10 === 1 && mod100 !== 11) return "подкатегория";
      if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) return "подкатегории";
      return "подкатегорий";
    },
    pluralizeSources(n) {
      const mod10 = n % 10;
      const mod100 = n % 100;
      if (mod10 === 1 && mod100 !== 11) return "источник";
      if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) return "источника";
      return "источников";
    },
  },
};
</script>

<style lang="stylus" scoped>
.showcase-row
  padding 8px 12px
  border 1px solid var(--gray-dark, #e0e0e0)
  border-radius 6px
  background var(--white, #fff)
  display flex
  align-items center
  gap 8px
  transition all 0.15s

  &--clickable
    cursor pointer

    &:hover
      border-color var(--pink, #ff8c00)
      background var(--pink-50, #fff5e6)

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
    accent-color var(--pink, #ff8c00)

  &__info
    flex 1
    min-width 0

  &__title
    font-weight inherit
    color var(--dark, #222)
    word-break break-word

  &__meta
    color var(--dark-light, #888)
    font-size 11px
    margin-top 2px
    font-weight normal

  &__badges
    display flex
    gap 6px
    flex-shrink 0
    flex-wrap wrap
    justify-content flex-end

  &__children
    display flex
    flex-direction column
    gap 4px
    margin-top 4px

.badge
  padding 2px 8px
  border-radius 4px
  font-size 11px
  white-space nowrap

  &--sources
    background var(--blue, #007bff)
    color var(--white, #fff)

  &--warn
    background var(--red, #dc3545)
    color var(--white, #fff)
</style>
