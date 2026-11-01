<template>
  <main class="page--main categories-page">
    <div class="categories-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="categories-page__actions">
        <input
          v-model="searchQuery"
          type="text"
          class="categories-page__search"
          placeholder="Поиск по названию"
        />
        <button v-if="tree.length" @click="openRootReorder" class="btn btn--white btn--md">
          <IconComponent name="drag-move-line" />
          <span>Изменить порядок</span>
        </button>
        <button @click="openModal" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Добавить категорию</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="categories-page__status">Загрузка дерева категорий…</div>
    <div v-else-if="!filteredTree.length" class="categories-page__status">
      {{ searchQuery ? "Ничего не найдено" : "Категорий пока нет" }}
    </div>
    <ul v-else class="categories-page__tree">
      <CategoryTreeItem
        v-for="item in filteredTree"
        :key="item.id"
        :category="item"
        @delete="confirmDelete"
        @reorder="openChildrenReorder"
        @toggle-active="toggleActive"
      />
    </ul>
  </main>
</template>

<script>
import { shallowRef } from "vue";
import CategoryTreeItem from "@/views/categories/components/CategoryTreeItem.vue";
import IconComponent from "@/components/IconComponent.vue";
import ChangePositionModal from "@/views/categories/modals/ChangePositionModal.vue";
import { useDefaultStore } from "@/stores/default.js";
import CATEGORIES_TREE from "@/graphql/queries/categories_tree.graphql";
import ENTITY_DELETE from "@/graphql/mutations/EntityDelete.graphql";
import CATEGORY_HIDE from "@/graphql/mutations/CategoryHide.graphql";
import CATEGORY_PUBLISH from "@/graphql/mutations/CategoryPublish.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "CategoriesPage",
  inject: ["apollo"],
  components: { IconComponent, CategoryTreeItem },
  data() {
    return {
      tree: [],
      loading: false,
      searchQuery: "",
      savedScroll: 0,
      hasSavedScroll: false,
    };
  },
  // Компонент кешируется через <keep-alive> (см. App.vue), поэтому состояние
  // дерева (раскрытие узлов) и scroll сохраняются при уходе на редактирование
  // категории и возврате назад.
  activated() {
    const restore = this.hasSavedScroll;
    // Сначала тихо обновляем данные (чтобы дерево не устарело после правки),
    // и только ПОСЛЕ перестроения дерева восстанавливаем scroll. Если вернуть
    // позицию до refetch, замена tree на миг схлопывает высоту списка и браузер
    // обрезает (clamp) прокрутку — позиция "уезжает" и назад не возвращается.
    this.get().finally(() => {
      if (!restore) return;
      this.$nextTick(() => {
        // behavior:"instant" обязателен — на странице глобальный
        // scroll-behavior:smooth, из-за которого обычный scrollTo
        // анимируется и не доезжает до сохранённой позиции надёжно.
        window.scrollTo({ top: this.savedScroll, behavior: "instant" });
      });
    });
  },
  // Сохраняем scroll ДО ухода с роута. Хук keep-alive deactivated срабатывает
  // уже после смены DOM на редактор (короткая страница), где прокрутка
  // склэмплена браузером, — там window.scrollY вернул бы испорченное значение.
  beforeRouteLeave(to, from, next) {
    this.savedScroll = window.scrollY;
    this.hasSavedScroll = true;
    next();
  },
  methods: {
    get() {
      // Индикатор загрузки — только при первом построении дерева. Тихий refetch
      // при возврате из редактирования (дерево уже есть) не схлопывает список,
      // иначе scroll-restore прыгнет.
      if (!this.tree.length) this.loading = true;
      return this.apollo()
        .admin.query({
          query: CATEGORIES_TREE,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const roots = data?.parent_categories || [];
          this.tree = [...roots].sort((a, b) => (a.position ?? 0) - (b.position ?? 0));
        })
        .catch((error) => {
          console.error("CategoriesTree load error:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openModal() {
      this.$router.push({ name: "category" });
    },
    openReorder(items, title) {
      const data = [...items].sort((a, b) => (a.position ?? 0) - (b.position ?? 0));
      useDefaultStore().modals.push({
        component: shallowRef(ChangePositionModal),
        options: {
          data,
          title,
          callback: this.get,
        },
      });
    },
    openRootReorder() {
      this.openReorder(this.tree, "Изменить порядок категорий");
    },
    openChildrenReorder(category) {
      const children = Array.isArray(category.child_categories) ? category.child_categories : [];
      if (!children.length) return;
      this.openReorder(children, `Порядок в «${category.title}»`);
    },
    toggleActive(category) {
      const makeActive = category.is_active === false;
      this.apollo()
        .admin.mutate({
          mutation: makeActive ? CATEGORY_PUBLISH : CATEGORY_HIDE,
          variables: { id: category.id },
        })
        .then(() => this.get())
        .catch((error) => {
          console.error("Category toggle active error:", error);
          alert(error.message || "Не удалось изменить видимость категории");
        });
    },
    confirmDelete(category) {
      const confirmed = window.confirm(`Удалить категорию «${category.title}»? Действие необратимо.`);
      if (!confirmed) return;
      this.apollo()
        .admin.mutate({
          mutation: ENTITY_DELETE,
          variables: { id: category.id, table: "categories" },
        })
        .then(() => this.get())
        .catch((error) => {
          console.error("Category delete error:", error);
          alert(error.message || "Не удалось удалить категорию");
        });
    },
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Категории");
    },
    filteredTree() {
      const q = this.searchQuery.trim().toLowerCase();
      if (!q) return this.tree;
      const filter = (nodes) =>
        nodes
          .map((node) => {
            const children = Array.isArray(node.child_categories) ? filter(node.child_categories) : [];
            const selfMatch = node.title.toLowerCase().includes(q);
            if (selfMatch || children.length) {
              return { ...node, child_categories: children };
            }
            return null;
          })
          .filter(Boolean);
      return filter(this.tree);
    },
  },
};
</script>

<style lang="stylus">
.categories-page
  &__header
    display flex
    justify-content space-between
    align-items center
    gap 16px
    margin-bottom 16px

  &__actions
    display flex
    align-items center
    gap 12px

  &__search
    padding 8px 12px
    min-width 260px
    border 1px solid var(--gray-3)
    border-radius 6px
    font-size 0.9375rem

    &:focus
      outline none
      border-color var(--main)

  &__status
    padding 24px
    text-align center
    color var(--dark-o50)

  &__tree
    padding 0
    margin 0
    border 1px solid var(--gray-3)
    border-radius 8px
    overflow hidden
    background #fff
</style>
