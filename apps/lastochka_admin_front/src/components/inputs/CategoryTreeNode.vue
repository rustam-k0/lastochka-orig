<template>
  <li class="tree-node">
    <div
      class="tree-node__row"
      :class="{
        'tree-node__row--disabled': leafOnly && hasChildren,
        'tree-node__row--active': isSelected,
      }"
      :style="{ paddingLeft: depth * 20 + 10 + 'px' }"
      @click="handleClick"
    >
      <span v-if="hasChildren" class="tree-node__toggle" @click.stop="toggleExpand">
        <IconComponent :name="expandedEffective ? 'arrow-down-s-fill' : 'arrow-right-s-fill'" />
      </span>
      <span v-else class="tree-node__toggle tree-node__toggle--leaf"></span>
      <CheckboxComponent
        v-if="multiple"
        :model-value="checkboxValue"
        :indeterminate="isIndeterminate"
        :disabled="leafOnly && hasChildren"
        @click.stop
        @update:model-value="handleClick"
      >
        <span :class="{ 'tree-node__label--parent': hasChildren }">{{ category.title }}</span>
      </CheckboxComponent>
      <span v-else class="tree-node__label" :class="{ 'tree-node__label--parent': hasChildren }">
        {{ category.title }}
      </span>
      <span v-if="showCounter && hasChildren" class="tree-node__counter">
        {{ selectedDescendantsCount }}/{{ descendantIds.length }}
      </span>
    </div>
    <ul v-if="hasChildren && expandedEffective" class="tree-node__children">
      <CategoryTreeNode
        v-for="child in category.child_categories"
        :key="child.id"
        :category="child"
        :depth="depth + 1"
        :multiple="multiple"
        :leaf-only="leafOnly"
        :selected="selected"
        :default-expanded="defaultExpanded"
        :search-active="searchActive"
        :show-counter="showCounter"
        :tri-state="triState"
        @select="$emit('select', $event)"
      />
    </ul>
  </li>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";

export default {
  name: "CategoryTreeNode",
  components: { IconComponent, CheckboxComponent },
  emits: ["select"],
  props: {
    category: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    multiple: Boolean,
    leafOnly: Boolean,
    selected: { type: Array, default: () => [] },
    defaultExpanded: { type: Boolean, default: true },
    searchActive: Boolean,
    showCounter: Boolean,
    triState: Boolean,
  },
  data() {
    return {
      expanded: this.defaultExpanded,
    };
  },
  computed: {
    hasChildren() {
      return this.category.child_categories && this.category.child_categories.length > 0;
    },
    isSelected() {
      return this.selected.includes(this.category.id);
    },
    expandedEffective() {
      return this.searchActive || this.expanded;
    },
    descendantIds() {
      const ids = [];
      const walk = (cat) => {
        for (const child of cat.child_categories || []) {
          ids.push(child.id);
          walk(child);
        }
      };
      walk(this.category);
      return ids;
    },
    selectedDescendantsCount() {
      const set = new Set(this.selected);
      return this.descendantIds.filter((id) => set.has(id)).length;
    },
    isFullySelected() {
      return this.isSelected && this.selectedDescendantsCount === this.descendantIds.length;
    },
    checkboxValue() {
      return this.triState ? this.isFullySelected : this.isSelected;
    },
    isIndeterminate() {
      if (!this.triState || this.isFullySelected) return false;
      return this.isSelected || this.selectedDescendantsCount > 0;
    },
  },
  methods: {
    toggleExpand() {
      this.expanded = !this.expanded;
    },
    handleClick() {
      if (this.leafOnly && this.hasChildren) {
        this.toggleExpand();
        return;
      }
      this.$emit("select", this.category);
    },
  },
};
</script>

<style lang="stylus">
.tree-node
  list-style none

  &__row
    display flex
    align-items center
    gap 4px
    padding 4px 10px
    cursor pointer
    transition background .15s
    min-height 32px

    &:hover
      background var(--main-o05)

    &--disabled
      cursor default

      &:hover
        background transparent

    &--active
      background var(--main-o05)

  &__toggle
    display inline-flex
    align-items center
    justify-content center
    width 20px
    height 20px
    flex-shrink 0
    cursor pointer

    .icon svg
      width 12px
      height 12px

    &--leaf
      cursor default

  &__label
    font-weight 500
    font-size 0.875rem
    line-height 18px
    color var(--dark)

    &--parent
      font-weight 600
      color var(--dark)
      opacity 0.6

  &__counter
    margin-left auto
    padding-left 8px
    font-weight 500
    font-size 0.75rem
    color var(--dark-light)
    flex-shrink 0

  &__children
    padding 0
    margin 0

  .checkbox
    padding 0

    &__field
      gap 5px
      align-items center
      padding 0
</style>
