<template>
  <section class="showcase-column">
    <header class="showcase-column__header">
      <h3>Витринные категории <small>(отображаются в приложении)</small></h3>
      <button class="btn btn--md showcase-column__create" @click="onCreate">
        <span>+ Создать</span>
      </button>
    </header>

    <div class="showcase-column__search">
      <input
        type="text"
        :value="search"
        placeholder="Поиск по витринным категориям..."
        @input="onSearchInput"
      />
    </div>

    <div v-if="canLink && selectedShowcaseIds.length === 0" class="showcase-column__hint">
      Кликните по листовой категории, чтобы привязать к ней выбранные категории 1С, либо отметьте чекбоксами несколько витрин для массовой привязки.
    </div>
    <div v-if="selectedShowcaseIds.length > 0" class="showcase-column__hint showcase-column__hint--multi">
      Выбрано витрин: {{ selectedShowcaseIds.length }}. Нажмите «Привязать» в нижней панели или снимите выбор.
    </div>

    <div v-if="loading" class="showcase-column__status">Загрузка…</div>
    <div v-else-if="!tree.length" class="showcase-column__status">
      {{ search ? "Ничего не найдено" : "Категорий нет" }}
    </div>

    <div v-else class="showcase-column__rows">
      <ShowcaseCategoryRow
        v-for="node in tree"
        :key="node.id"
        :row="node"
        :level="0"
        :has-selection="canLink"
        :selected-ids="selectedShowcaseIds"
        @link="handleLinkClick"
        @toggle-select="$emit('toggle-showcase', $event)"
      />
    </div>
  </section>
</template>

<script>
import ShowcaseCategoryRow from "./ShowcaseCategoryRow.vue";
import CATEGORIES_SHOWCASE from "../graphql/categoriesShowcase.graphql";

export default {
  name: "ShowcaseColumn",
  inject: ["apollo"],
  components: { ShowcaseCategoryRow },
  props: {
    shopId: { type: [Number, String], default: null },
    search: { type: String, default: "" },
    selectedOneCIds: { type: Array, default: () => [] },
    selectedShowcaseIds: { type: Array, default: () => [] },
    refreshKey: { type: Number, default: 0 },
  },
  emits: ["update:search", "drop-link", "toggle-showcase"],
  data() {
    return {
      flatRows: [],
      loading: false,
      searchTimeout: null,
    };
  },
  computed: {
    canLink() {
      return this.selectedOneCIds.length > 0;
    },
    /**
     * Дерево витринных категорий.
     *
     * Backend возвращает плоский список с `parent { id }`. Строим дерево локально:
     * roots — категории без parent, children навешиваются по parent.id.
     * Если применён search-фильтр — backend уже отфильтровал; в дереве могут оказаться
     * только подходящие узлы и их предки, поэтому ancestors-фильтра здесь не делаем
     * (разница с OneCColumn — там backend отдаёт ВСЕ узлы независимо от фильтра).
     */
    tree() {
      const map = new Map();
      for (const r of this.flatRows) {
        map.set(r.id, { ...r, children: [] });
      }
      const roots = [];
      for (const node of map.values()) {
        const parentId = node.parent?.id;
        if (parentId && map.has(parentId)) {
          map.get(parentId).children.push(node);
        } else {
          roots.push(node);
        }
      }
      const sortByTitle = (a, b) => (a.title || "").localeCompare(b.title || "", "ru");
      const sortRecursive = (nodes) => {
        nodes.sort(sortByTitle);
        for (const n of nodes) sortRecursive(n.children);
      };
      sortRecursive(roots);
      return roots;
    },
  },
  watch: {
    shopId() {
      this.fetch();
    },
    search() {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => this.fetch(), 300);
    },
    refreshKey() {
      this.fetch();
    },
  },
  mounted() {
    if (this.shopId) this.fetch();
  },
  methods: {
    fetch() {
      if (!this.shopId) return;
      this.loading = true;
      this.apollo()
        .admin.query({
          query: CATEGORIES_SHOWCASE,
          variables: {
            shop_id: parseInt(this.shopId),
            title: this.search || null,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.flatRows = data?.admin_categories_paginate?.data || [];
        })
        .catch((error) => {
          console.error("ShowcaseColumn: fetch error", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    onSearchInput(event) {
      this.$emit("update:search", event.target.value);
    },
    handleLinkClick({ id, title }) {
      if (!this.canLink) return;
      this.$emit("drop-link", {
        showcase_category_id: id,
        showcase_title: title,
        override_one_c_ids: null,
      });
    },
    onCreate() {
      // Создание витринных категорий уже реализовано на отдельном экране (Категории).
      // Открываем его в новой вкладке, чтобы не терять контекст текущей привязки 1С.
      const route = this.$router.resolve({ name: "categories" });
      window.open(route.href, "_blank");
    },
    refresh() {
      this.fetch();
    },
  },
};
</script>

<style lang="stylus" scoped>
.showcase-column
  display flex
  flex-direction column
  background var(--white, #fafafa)
  padding 16px
  border-radius 8px
  min-height 400px
  border 1px solid var(--gray-dark, #e0e0e0)

  &__header
    display flex
    justify-content space-between
    align-items center
    margin-bottom 12px

    h3
      font-size 1rem
      font-weight 600
      color var(--dark, #222)

      small
        font-size 0.75rem
        color var(--dark-light, #888)
        font-weight normal
        margin-left 6px

  &__create
    background var(--pink, #ff8c00)
    color var(--white, #fff)
    border none
    padding 6px 14px
    border-radius 4px
    cursor pointer
    font-size 13px
    transition opacity 0.15s

    &:hover
      opacity 0.9

  &__search
    margin-bottom 12px

    input
      width 100%
      padding 8px 12px
      border 1px solid var(--gray-dark, #ddd)
      border-radius 4px
      font-size 14px
      outline none

      &:focus
        border-color var(--pink, #ff8c00)

  &__hint
    font-size 12px
    color var(--pink-dark, #cc6600)
    background var(--pink-50, #fff5e6)
    padding 8px 12px
    border-radius 4px
    margin-bottom 12px

    &--multi
      color var(--blue-dark, #0d47a1)
      background var(--blue-50, #e3f2fd)

  &__status
    text-align center
    color var(--dark-light, #888)
    padding 32px
    font-size 14px

  &__rows
    display flex
    flex-direction column
    gap 4px
    overflow-y auto
    max-height 700px
    padding-right 4px
</style>
