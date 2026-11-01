<template>
  <div
    class="category-tree-panel"
    :class="{
      'category-tree-panel--error': errors.length,
      'category-tree-panel--disabled': disabled,
    }"
  >
    <div class="category-tree-panel__card">
      <div class="category-tree-panel__header">
        <div class="category-tree-panel__search">
          <IconComponent name="search-line" />
          <input type="text" v-model="query" placeholder="Поиск по категориям" :disabled="disabled" />
        </div>
        <button class="category-tree-panel__action" type="button" :disabled="disabled" @click="selectAll">
          Выбрать все
        </button>
        <button class="category-tree-panel__action" type="button" :disabled="disabled" @click="clearAll">
          Снять все
        </button>
        <span class="category-tree-panel__counter">Выбрано {{ selectedCount }} из {{ totalCount }}</span>
      </div>
      <ul class="category-tree-panel__list">
        <template v-if="filteredTree.length">
          <CategoryTreeNode
            v-for="cat in filteredTree"
            :key="cat.id"
            :category="cat"
            :depth="0"
            multiple
            tri-state
            show-counter
            :default-expanded="false"
            :search-active="searchActive"
            :selected="selectedIds"
            @select="onSelect"
          />
        </template>
        <li v-else class="category-tree-panel__empty">
          {{ searchActive ? "Ничего не найдено" : "Пусто" }}
        </li>
      </ul>
    </div>
    <div class="category-tree-panel__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import CategoryTreeNode from "@/components/inputs/CategoryTreeNode.vue";

export default {
  name: "CategoryTreePanel",
  components: { IconComponent, CategoryTreeNode },
  emits: ["update:model-value"],
  props: {
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    errors: { type: Array, default: () => [] },
    disabled: Boolean,
  },
  data: () => ({
    query: "",
  }),
  computed: {
    selectedIds() {
      return (this.modelValue || []).map((v) => v.id);
    },
    allNodes() {
      const all = [];
      const walk = (cats) => {
        for (const cat of cats) {
          all.push(cat);
          walk(cat.child_categories || []);
        }
      };
      walk(this.options);
      return all;
    },
    totalCount() {
      return this.allNodes.length;
    },
    selectedCount() {
      const set = new Set(this.selectedIds);
      return this.allNodes.filter((cat) => set.has(cat.id)).length;
    },
    searchActive() {
      return this.query.trim().length > 0;
    },
    filteredTree() {
      if (!this.searchActive) return this.options;
      return this.filterTree(this.options, this.query.trim().toLowerCase());
    },
  },
  methods: {
    filterTree(categories, q) {
      const result = [];
      for (const cat of categories) {
        const children = cat.child_categories ? this.filterTree(cat.child_categories, q) : [];
        const nameMatch = (cat.title || "").toLowerCase().includes(q);
        if (nameMatch || children.length) {
          result.push({
            ...cat,
            child_categories: nameMatch ? cat.child_categories : children,
          });
        }
      }
      return result;
    },
    findInTree(categories, id) {
      for (const cat of categories) {
        if (cat.id === id) return cat;
        if (cat.child_categories?.length) {
          const found = this.findInTree(cat.child_categories, id);
          if (found) return found;
        }
      }
      return null;
    },
    collectSubtree(cat) {
      const items = [{ id: cat.id, title: cat.title }];
      for (const child of cat.child_categories || []) {
        items.push(...this.collectSubtree(child));
      }
      return items;
    },
    onSelect(cat) {
      if (this.disabled) return;
      // Каскад по полному поддереву из options: при поиске узел приходит
      // с отфильтрованными child_categories, а выбор должен затрагивать
      // всех потомков, не только видимых.
      const source = this.findInTree(this.options, cat.id) || cat;
      const subtree = this.collectSubtree(source);
      const arr = JSON.parse(JSON.stringify(this.modelValue || []));
      const selected = new Set(arr.map((v) => v.id));
      const fullySelected = subtree.every((s) => selected.has(s.id));
      if (fullySelected) {
        const ids = new Set(subtree.map((s) => s.id));
        this.$emit(
          "update:model-value",
          arr.filter((v) => !ids.has(v.id))
        );
      } else {
        for (const s of subtree) {
          if (!selected.has(s.id)) arr.push(s);
        }
        this.$emit("update:model-value", arr);
      }
    },
    selectAll() {
      if (this.disabled) return;
      this.$emit(
        "update:model-value",
        this.allNodes.map((cat) => ({ id: cat.id, title: cat.title }))
      );
    },
    clearAll() {
      if (this.disabled) return;
      this.$emit("update:model-value", []);
    },
  },
};
</script>

<style lang="stylus">
.category-tree-panel
  width 100%
  display grid
  grid-gap 5px

  &--disabled
    opacity 0.6
    pointer-events none

  &__card
    background var(--white)
    border 1px solid var(--border-color)
    border-radius 5px
    overflow hidden

    ^[0]--error &
      border-color var(--red)

  &__header
    display flex
    align-items center
    gap 12px
    padding 8px 12px
    border-bottom 1px solid var(--border-color)
    flex-wrap wrap

  &__search
    display flex
    align-items center
    gap 8px
    flex 1 1 200px
    min-width 160px

    .icon svg
      width 16px
      height 16px
      opacity 0.5

    input
      appearance none
      border none
      background none
      outline none
      width 100%
      height 32px
      font-weight 500
      font-size 0.875rem
      color var(--dark)

      &::placeholder
        font-weight 400
        color var(--gray-2)

  &__action
    background none
    border none
    padding 4px 0
    font-weight 500
    font-size 0.813rem
    color var(--main)
    cursor pointer
    white-space nowrap

    &:hover
      text-decoration underline

    &:disabled
      cursor default
      opacity 0.5
      text-decoration none

  &__counter
    margin-left auto
    font-weight 500
    font-size 0.75rem
    color var(--dark-light)
    white-space nowrap

  &__list
    margin 0
    padding 5px 0
    list-style none
    max-height 400px
    overflow auto
    scrollbar-width thin
    scrollbar-color rgba(0, 0, 0, 0.3) transparent

    &::-webkit-scrollbar
      width 8px
      height 8px

    &::-webkit-scrollbar-track
      background transparent

    &::-webkit-scrollbar-thumb
      background-clip padding-box
      background-color rgba(0, 0, 0, 0.3)
      border-radius 3px
      border none

  &__empty
    font-weight 500
    font-size 0.875rem
    line-height 16px
    color var(--dark)
    padding 8px 12px

  &__errors
    font-weight 400
    font-size 0.75rem
    line-height 15px
    color var(--red)
    display grid
    grid-gap 3px
</style>
