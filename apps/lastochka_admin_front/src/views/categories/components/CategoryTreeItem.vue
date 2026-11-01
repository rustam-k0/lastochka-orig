<template>
  <li class="category-tree-item">
    <div class="category-tree-item__row" :class="{ 'category-tree-item__row--leaf': !hasChildren }">
      <span
        class="category-tree-item__toggle"
        :class="{ 'category-tree-item__toggle--leaf': !hasChildren }"
        @click="toggleExpand"
      >
        <IconComponent v-if="hasChildren" :name="expanded ? 'arrow-down-s-fill' : 'arrow-right-s-fill'" />
      </span>
      <router-link
        :to="{ name: 'category', params: { id: category.id } }"
        class="category-tree-item__title"
        :class="{
          'category-tree-item__title--parent': hasChildren,
          'category-tree-item__title--inactive': isInactive,
        }"
      >
        {{ category.title }}
      </router-link>
      <span v-if="isInactive" class="category-tree-item__badge category-tree-item__badge--inactive">
        скрыта
      </span>
      <span v-if="category.important" class="category-tree-item__badge category-tree-item__badge--important">
        важная
      </span>
      <span v-if="category.is_cooking" class="category-tree-item__badge">кулинария</span>
      <span v-if="category.stock_mode === 'ignore'" class="category-tree-item__badge category-tree-item__badge--stock-ignore">без учёта</span>
      <span v-if="category.stock_mode === 'fixed'" class="category-tree-item__badge category-tree-item__badge--stock-fixed">лимит {{ category.fixed_stock_quantity }}</span>
      <span
        v-if="!hasChildren && category.tag_text"
        class="category-tree-item__badge category-tree-item__badge--tag"
        :style="{ background: category.tag_color || '#3B82F6' }"
      >{{ category.tag_text }}</span>
      <div class="category-tree-item__actions">
        <button
          type="button"
          class="category-tree-item__action"
          :title="isInactive ? 'Показать на витрине' : 'Скрыть с витрины'"
          @click="$emit('toggle-active', category)"
        >
          <IconComponent :name="isInactive ? 'eye-off-line' : 'eye-line'" />
        </button>
        <button
          v-if="hasChildren"
          type="button"
          class="category-tree-item__action"
          title="Изменить порядок подкатегорий"
          @click="$emit('reorder', category)"
        >
          <IconComponent name="drag-move-line" />
        </button>
        <router-link
          :to="{ name: 'category', params: { id: category.id } }"
          class="category-tree-item__action"
          title="Редактировать"
        >
          <IconComponent name="pencil-line" />
        </router-link>
        <button
          type="button"
          class="category-tree-item__action category-tree-item__action--danger"
          title="Удалить"
          @click="$emit('delete', category)"
        >
          <IconComponent name="delete-bin-line" />
        </button>
      </div>
    </div>
    <ul v-if="hasChildren && expanded" class="category-tree-item__children">
      <CategoryTreeItem
        v-for="child in sortedChildren"
        :key="child.id"
        :category="child"
        :depth="depth + 1"
        @delete="$emit('delete', $event)"
        @reorder="$emit('reorder', $event)"
        @toggle-active="$emit('toggle-active', $event)"
      />
    </ul>
  </li>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "CategoryTreeItem",
  components: { IconComponent },
  emits: ["delete", "reorder", "toggle-active"],
  props: {
    category: { type: Object, required: true },
    depth: { type: Number, default: 0 },
  },
  data() {
    return {
      expanded: this.depth < 1,
    };
  },
  computed: {
    isInactive() {
      return this.category.is_active === false;
    },
    hasChildren() {
      return Array.isArray(this.category.child_categories) && this.category.child_categories.length > 0;
    },
    sortedChildren() {
      if (!this.hasChildren) return [];
      return [...this.category.child_categories].sort((a, b) => (a.position ?? 0) - (b.position ?? 0));
    },
  },
  methods: {
    toggleExpand() {
      if (!this.hasChildren) return;
      this.expanded = !this.expanded;
    },
  },
};
</script>

<style lang="stylus">
.category-tree-item
  list-style none

  &__row
    position relative
    display flex
    align-items center
    gap 8px
    padding 8px 12px
    min-height 40px
    border-bottom 1px solid var(--gray-3)
    transition background .15s

    &:hover
      background var(--main-o05)

      .category-tree-item__actions
        opacity 1

  &__toggle
    display inline-flex
    align-items center
    justify-content center
    width 20px
    height 20px
    flex-shrink 0
    cursor pointer

    .icon svg
      width 14px
      height 14px

    &--leaf
      cursor default

  &__title
    font-weight 500
    font-size 0.9375rem
    color var(--dark)
    text-decoration none

    &:hover
      text-decoration underline

    &--parent
      font-weight 600

    &--inactive
      color var(--dark-o50)

  &__badge
    display inline-block
    padding 2px 8px
    font-size 0.75rem
    font-weight 500
    color var(--main)
    background var(--main-o05)
    border-radius 10px

    &--important
      color var(--orange, #e07c00)
      background rgba(224, 124, 0, 0.1)

    &--inactive
      color var(--dark-o50)
      background var(--gray-3)

    &--stock-ignore
      color #6d6d6d
      background #f0f0f0

    &--stock-fixed
      color #1565C0
      background #E3F2FD

    &--tag
      color #fff

  &__actions
    margin-left auto
    display flex
    gap 4px
    opacity 0
    transition opacity .15s

  &__action
    display inline-flex
    align-items center
    justify-content center
    width 28px
    height 28px
    border none
    background transparent
    color var(--dark-o50)
    border-radius 4px
    cursor pointer
    text-decoration none
    transition background .15s, color .15s

    &:hover
      background var(--gray-3)
      color var(--dark)

    &--danger:hover
      color var(--red, #d32f2f)
      background rgba(211, 47, 47, 0.08)

    .icon svg
      width 16px
      height 16px

  // Направляющие линии иерархии: вертикальная полоса вдоль группы детей
  // «вытекает» из-под стрелки родителя, к каждому ребёнку — горизонтальный отвод.
  &__children
    padding 0
    margin 0 0 0 22px
    border-left 1px solid var(--gray-dark-3)

    > .category-tree-item > .category-tree-item__row::before
      content ''
      position absolute
      left -1px
      top 20px
      width 12px
      height 1px
      background var(--gray-dark-3)

    // У листьев (без раскрывающей стрелки) отвод тянется почти до названия
    // — оставляем небольшой зазор, у родителей линия упирается в стрелку.
    // Через класс-модификатор (Stylus некорректно компилирует :has()).
    > .category-tree-item > .category-tree-item__row--leaf::before
      width 34px
</style>
