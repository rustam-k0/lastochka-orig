<template>
  <div
    v-click-outside="close"
    class="category-tree-select"
    :class="{
      'category-tree-select--focused': focused,
      'category-tree-select--error': errors.length,
      'category-tree-select--disabled': disabled,
      'category-tree-select--values-visible': !focused && multiple && modelValue.length,
    }"
  >
    <label class="category-tree-select__title" :for="id" v-if="title">
      {{ title }}
      <span v-if="required">*</span>
    </label>
    <div class="category-tree-select__input-container">
      <div class="category-tree-select__field" ref="input">
        <label class="category-tree-select__field-label" :for="id" aria-hidden="true"></label>
        <input
          :id="id"
          type="text"
          class="category-tree-select__input"
          :value="inputValue"
          @input="query = $event.target.value"
          @focus="focus"
          :placeholder="placeholder"
          :disabled="disabled"
        />
        <div class="category-tree-select__values" v-if="multiple">
          <label :for="id"></label>
          <button type="button" v-for="(value, i) in modelValue" :key="i" @click="remove(i)">
            <span>{{ value.title }}</span>
            <IconComponent name="close-fill" />
          </button>
        </div>
        <button class="category-tree-select__clear" type="button" @click="clear" v-if="canShowClear">
          <IconComponent name="close-fill" />
        </button>
        <label v-if="!disabled" :for="id" class="category-tree-select__arrow">
          <IconComponent name="arrow-down-s-fill" />
        </label>
      </div>
      <ul class="category-tree-select__list" v-if="focused">
        <template v-if="filteredTree.length">
          <CategoryTreeNode
            v-for="cat in filteredTree"
            :key="cat.id"
            :category="cat"
            :depth="0"
            :multiple="multiple"
            :leaf-only="leafOnly"
            :selected="selectedIds"
            @select="onSelect"
          />
        </template>
        <li v-else class="category-tree-select__empty">Пусто</li>
      </ul>
    </div>
    <div class="category-tree-select__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import CategoryTreeNode from "@/components/inputs/CategoryTreeNode.vue";

const uid = () => Date.now().toString(36) + Math.random().toString(36).substring(2);

export default {
  name: "CategoryTreeSelect",
  components: { IconComponent, CategoryTreeNode },
  emits: ["update:model-value"],
  props: {
    title: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    modelValue: [Array, Object],
    errors: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    required: Boolean,
    multiple: Boolean,
    leafOnly: Boolean,
    clearable: Boolean,
    disabled: Boolean,
  },
  data: () => ({
    query: "",
    focused: false,
    id: uid(),
  }),
  computed: {
    selectedIds() {
      if (this.multiple) {
        return (this.modelValue || []).map((v) => v.id);
      }
      return this.modelValue ? [this.modelValue.id] : [];
    },
    canShowClear() {
      if (!this.clearable) return false;
      if (this.multiple) return this.modelValue && this.modelValue.length;
      return !!this.modelValue;
    },
    inputValue() {
      if (this.focused) return this.query;
      if (!this.multiple && this.modelValue) return this.modelValue.title;
      return "";
    },
    filteredTree() {
      if (!this.query) return this.options;
      return this.filterTree(this.options, this.query.toLowerCase());
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
    focus() {
      this.focused = true;
    },
    close() {
      this.focused = false;
      this.query = "";
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
      if (this.multiple) {
        // Каскад по полному поддереву из options: при поиске узел приходит
        // с отфильтрованными child_categories, а выбор родителя должен
        // затрагивать всех потомков, не только видимых.
        const source = this.findInTree(this.options, cat.id) || cat;
        const subtree = this.collectSubtree(source);
        const arr = JSON.parse(JSON.stringify(this.modelValue || []));
        const wasSelected = arr.some((v) => v.id === cat.id);
        if (wasSelected) {
          const ids = new Set(subtree.map((s) => s.id));
          this.$emit(
            "update:model-value",
            arr.filter((v) => !ids.has(v.id))
          );
        } else {
          const existing = new Set(arr.map((v) => v.id));
          for (const s of subtree) {
            if (!existing.has(s.id)) arr.push(s);
          }
          this.$emit("update:model-value", arr);
        }
      } else {
        this.$emit("update:model-value", { id: cat.id, title: cat.title });
        this.close();
      }
    },
    remove(i) {
      if (this.disabled) return;
      const arr = JSON.parse(JSON.stringify(this.modelValue));
      arr.splice(i, 1);
      this.$emit("update:model-value", arr);
    },
    clear() {
      if (this.disabled) return;
      this.$emit("update:model-value", this.multiple ? [] : null);
    },
  },
  directives: {
    "click-outside": {
      mounted(el, binding) {
        el.clickOutsideEvent = (event) => {
          if (!(el === event.target || el.contains(event.target))) {
            binding.value(event);
          }
        };
        document.body.addEventListener("click", el.clickOutsideEvent);
      },
      beforeUnmount(el) {
        document.body.removeEventListener("click", el.clickOutsideEvent);
      },
    },
  },
};
</script>

<style lang="stylus">
.category-tree-select
  width 100%
  display inline-grid
  grid-gap 5px
  position relative

  &__title
    font-weight 400
    font-size 0.813rem
    line-height 18px
    color var(--black-2)

    ^[0]--error &
      color var(--red)

    > span
      color var(--red)

  &__input-container
    position relative
    width 100%

  &__field
    display flex
    background var(--white)
    border 1px solid var(--border-color)
    border-radius 5px
    height 48px
    transition background .2s, box-shadow .2s, border-color .2s
    align-items stretch
    justify-content stretch
    padding 1px 16px
    position relative
    padding-right 1px
    width 100%
    overflow hidden
    z-index 2

    ^[0]--values-visible &
      background var(--white)
      height auto
      align-items center

    ^[0]--focused &
      padding 0 15px
      padding-right 0
      background var(--white)
      z-index 11

    ^[0]--error &
      background var(--white)
      border-color var(--red)

  &__field-label
    position absolute
    left 0
    top 0
    width 100%
    height 100%
    cursor text
    border-radius 6px

    ^[0]--disabled &
      background var(--neutral-50)

  &__input
    appearance none
    border none
    background none
    outline none
    font-weight 500
    font-size 0.875rem
    line-height 20px
    color var(--dark)
    height 100%
    width 100%

    ^[0]--disabled &
      color var(--body-color-o5)
      z-index 1

    &::placeholder
      font-weight 400
      font-size 0.875rem
      line-height 16px
      color var(--gray-2)

    ^[0]--values-visible &
      width 0
      height 0
      opacity 0
      overflow hidden

  &__arrow
    height 100%
    padding 0 15px
    display inline-flex
    align-items center
    justify-content center
    z-index 1
    cursor pointer

    .icon svg
      width 10px
      height 10px
      transition .2s

      ^[0]--focused &
        transform rotate(180deg)

  &__clear
    height 100%
    background none
    border none
    padding 0 15px
    margin-right -15px
    display inline-flex
    align-items center
    justify-content center
    z-index 1

    ^[0]--focused &
      display none

    .icon svg
      width 16px
      height 16px

  &__values
    height 100%
    width 100%
    overflow hidden
    align-items center
    gap 5px
    display none
    position relative

    ^[0]--values-visible &
      display flex
      flex-wrap wrap
      padding 10px 0

    label
      position absolute
      left 0
      top 0
      width 100%
      bottom 0
      z-index 1
      cursor text

    button
      z-index 2
      background var(--blue-light)
      border 1px solid transparent
      border-radius 5px
      display inline-flex
      align-items center
      gap 5px
      padding 4px 8px
      font-weight 500
      font-size 0.75rem
      white-space nowrap
      line-height 20px
      color var(--dark)

      .icon
        width 18px
        height 18px
        padding 3px
        border-radius 999px
        background var(--white)

        svg path
          fill var(--dark)
          width 12px
          height 12px

  &__list
    position absolute
    z-index 10
    width 100%
    max-height 300px
    overflow auto
    background var(--white)
    border-radius 10px
    box-shadow 0 15px 30px rgba(0, 0, 0, 0.15)
    padding 5px 0
    bottom 0
    transform translateY(100%)
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
    padding 5px 10px

  &__errors
    font-weight 400
    font-size 0.75rem
    line-height 15px
    opacity 1
    color var(--red)
    display grid
    grid-gap 3px
</style>
