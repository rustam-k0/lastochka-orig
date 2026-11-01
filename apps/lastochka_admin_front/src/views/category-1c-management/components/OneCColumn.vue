<template>
  <section class="one-c-column">
    <header class="one-c-column__header">
      <h3>Категории 1С <small>(источник, read-only)</small></h3>
      <span class="one-c-column__count">
        <template v-if="hasActiveFilter">
          {{ visibleCount }} из {{ totalCount }} шт
        </template>
        <template v-else>{{ totalCount }} шт</template>
      </span>
    </header>

    <div v-if="showOnlyNew" class="one-c-column__active-filter">
      <span>Фильтр: только новые</span>
      <button class="one-c-column__active-filter-reset" @click="$emit('reset-show-only-new')">сбросить ×</button>
    </div>

    <div class="one-c-column__search">
      <input
        type="text"
        :value="search"
        placeholder="Поиск по категориям 1С..."
        @input="onSearchInput"
      />
    </div>

    <div class="one-c-column__filters">
      <button
        v-for="opt in filterOptions"
        :key="opt.value"
        class="one-c-column__filter"
        :class="{ 'one-c-column__filter--active': linkedFilter === opt.value }"
        @click="$emit('update:linkedFilter', opt.value)"
      >
        {{ opt.label }}
      </button>
    </div>

    <div v-if="loading" class="one-c-column__status">Загрузка…</div>
    <div v-else-if="!filteredTree.length" class="one-c-column__status">
      {{ search ? "Ничего не найдено" : "Категорий нет" }}
    </div>

    <div v-else class="one-c-column__rows">
      <OneCCategoryRow
        v-for="node in filteredTree"
        :key="node.id"
        :row="node"
        :level="0"
        :selected-ids="selectedIds"
        :matching-ids="matchingIds"
        @toggle="toggleSelect"
        @unlink="onUnlink"
        @unlink-showcase="$emit('unlink-showcase', $event)"
      />
    </div>
  </section>
</template>

<script>
import OneCCategoryRow from "./OneCCategoryRow.vue";
import CATEGORIES_ONE_C_TREE from "../graphql/categories1cTree.graphql";
import UNLINK_CATEGORIES_1C from "../graphql/unlinkCategories1c.graphql";

export default {
  name: "OneCColumn",
  inject: ["apollo"],
  components: { OneCCategoryRow },
  props: {
    shopId: { type: [Number, String], default: null },
    selectedIds: { type: Array, default: () => [] },
    linkedFilter: { type: String, default: "ALL" },
    search: { type: String, default: "" },
    showOnlyNew: { type: Boolean, default: false },
    refreshKey: { type: Number, default: 0 },
  },
  emits: ["update:selectedIds", "update:linkedFilter", "update:search", "linked-changed", "reset-show-only-new", "unlink-showcase"],
  data() {
    return {
      flatRows: [],
      loading: false,
      filterOptions: [
        { value: "UNLINKED", label: "Не привязаны" },
        { value: "LINKED", label: "Привязаны" },
        { value: "ALL", label: "Все" },
      ],
    };
  },
  computed: {
    totalCount() {
      return this.flatRows.length;
    },
    visibleCount() {
      // Считаем только листья среди matching, потому что только их можно выбрать.
      // Лист = id, который не является parent_id ни для кого (вычисляем локально).
      const parentIds = new Set();
      for (const r of this.flatRows) {
        if (r.parent_id !== null && r.parent_id !== undefined) parentIds.add(r.parent_id);
      }
      let n = 0;
      for (const r of this.flatRows) {
        if (!parentIds.has(r.id) && this.matchingIds.has(r.id)) n++;
      }
      return n;
    },
    hasActiveFilter() {
      return this.linkedFilter !== "ALL" || this.showOnlyNew || !!this.search;
    },
    /**
     * Множество ID узлов, которые "проходят" текущий фильтр (linked_status + showOnlyNew).
     * Только эти узлы показываются обычным цветом и могут быть выбраны (если is_leaf).
     * Остальные могут показываться как ancestors для контекста, но visual dim.
     */
    matchingIds() {
      const set = new Set();
      const term = (this.search || "").trim().toLowerCase();
      for (const row of this.flatRows) {
        let pass = true;
        if (this.linkedFilter === "LINKED" && !row.is_linked) pass = false;
        if (this.linkedFilter === "UNLINKED" && row.is_linked) pass = false;
        if (this.showOnlyNew && !row.is_new) pass = false;
        if (term && !(row.name || "").toLowerCase().includes(term)) pass = false;
        if (pass) set.add(row.id);
      }
      return set;
    },
    /**
     * Полное отсортированное дерево. Зависит ТОЛЬКО от flatRows — пересборка
     * (Map + spread 6206 узлов + рекурсивный localeCompare-сорт) выполняется
     * один раз на fetch, а не на каждый символ поиска/переключение фильтра.
     */
    sortedTree() {
      return this.buildTree(this.flatRows);
    },
    /**
     * Дерево, отфильтрованное так что показываются:
     *  - узлы из matchingIds
     *  - их предки (для сохранения иерархического контекста)
     * Предки, не входящие в matchingIds, помечены как "muted" в OneCCategoryRow.
     */
    filteredTree() {
      return this.pruneTree(this.sortedTree);
    },
  },
  watch: {
    shopId() {
      this.fetch();
    },
    refreshKey() {
      this.fetch();
    },
  },
  mounted() {
    if (this.shopId) this.fetch();
  },
  methods: {
    async fetch() {
      if (!this.shopId) return;
      this.loading = true;
      // Дерево строится на клиенте из плоского списка ВСЕХ категорий магазина
      // (фильтрация search + linked + showOnlyNew — локально через `matchingIds`,
      // предки-non-matching видны как контекст для поиска).
      // Используется непагинированный `categories_1c_tree`: один HTTP-запрос
      // вместо постраничного цикла по `categories_1c` (на 6206 категориях цикл
      // давал 31+ последовательный round-trip и минуты загрузки раздела).
      // Ответ — JSON-СТРОКА (по-полевой резолвинг GraphQL на 6206 строк стоил
      // секунды на dev-VM, см. PHPDoc TreeList в admin_back) — парсим сами.
      try {
        const { data } = await this.apollo().admin.query({
          query: CATEGORIES_ONE_C_TREE,
          variables: { shop_id: parseInt(this.shopId) },
          fetchPolicy: "network-only",
        });
        this.flatRows = JSON.parse(data?.categories_1c_tree || "[]");
      } catch (error) {
        // Сбрасываем список: иначе после смены магазина при упавшем запросе
        // остались бы категории ПРЕДЫДУЩЕГО магазина под видом нового.
        this.flatRows = [];
        console.error("OneCColumn: fetch error", error);
      } finally {
        this.loading = false;
      }
    },
    /**
     * flat list → tree by parent_id. Узлы без parent_id или с parent_id указывающим
     * на отсутствующего родителя считаются roots.
     */
    buildTree(rows) {
      const map = new Map();
      for (const r of rows) {
        map.set(r.id, { ...r, children: [], is_leaf: true });
      }
      const roots = [];
      for (const node of map.values()) {
        const parentId = node.parent_id;
        if (parentId && map.has(parentId)) {
          const parent = map.get(parentId);
          parent.children.push(node);
          parent.is_leaf = false;
        } else {
          roots.push(node);
        }
      }
      const sortByName = (a, b) => (a.name || "").localeCompare(b.name || "", "ru");
      const sortRecursive = (nodes) => {
        nodes.sort(sortByName);
        for (const n of nodes) sortRecursive(n.children);
      };
      sortRecursive(roots);
      return roots;
    },
    /**
     * Оставить узлы которые matching ИЛИ имеют matching потомка.
     */
    pruneTree(nodes) {
      const result = [];
      for (const n of nodes) {
        const prunedChildren = this.pruneTree(n.children);
        if (this.matchingIds.has(n.id) || prunedChildren.length > 0) {
          result.push({ ...n, children: prunedChildren });
        }
      }
      return result;
    },
    onSearchInput(event) {
      this.$emit("update:search", event.target.value);
    },
    toggleSelect(id) {
      const next = this.selectedIds.includes(id)
        ? this.selectedIds.filter((x) => x !== id)
        : [...this.selectedIds, id];
      this.$emit("update:selectedIds", next);
    },
    onUnlink(id) {
      if (!confirm("Отвязать эту категорию 1С от витринной категории?")) return;
      this.apollo()
        .admin.mutate({
          mutation: UNLINK_CATEGORIES_1C,
          variables: { category_1c_ids: [parseInt(id)] },
        })
        .then(() => {
          this.fetch();
          this.$emit("linked-changed");
        })
        .catch((error) => {
          console.error("OneCColumn: unlink error", error);
          alert("Не удалось отвязать: " + (error?.message || ""));
        });
    },
    refresh() {
      this.fetch();
    },
  },
};
</script>

<style lang="stylus" scoped>
.one-c-column
  display flex
  flex-direction column
  background var(--gray-50, #f7f7f7)
  padding 16px
  border-radius 8px
  min-height 400px

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

  &__count
    color var(--dark-light, #888)
    font-size 13px

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

  &__filters
    display flex
    gap 8px
    margin-bottom 12px
    flex-wrap wrap

  &__filter
    padding 4px 12px
    border 1px solid var(--gray-dark, #ccc)
    border-radius 16px
    background var(--white, #fff)
    cursor pointer
    font-size 13px
    transition all 0.15s

    &:hover
      border-color var(--pink, #ff8c00)

    &--active
      background var(--pink, #ff8c00)
      color var(--white, #fff)
      border-color var(--pink, #ff8c00)

  &__active-filter
    display flex
    align-items center
    justify-content space-between
    gap 8px
    padding 8px 12px
    margin-bottom 12px
    background var(--orange-light, #fff5e6)
    border 1px solid var(--orange, #ff8c00)
    border-radius 4px
    color var(--orange-dark, #cc6600)
    font-size 13px

  &__active-filter-reset
    background transparent
    border none
    color var(--orange-dark, #cc6600)
    cursor pointer
    font-size 12px
    text-decoration underline

    &:hover
      color var(--red, #dc3545)

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
