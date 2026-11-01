<template>
  <main class="pages-page">
    <div class="pages-page__header">
      <h2>{{ pageTitle }}</h2>
      <router-link :to="{ name: 'page-create' }" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать страницу</span>
      </router-link>
    </div>
    <TableComponent
      class="pages-page__table"
      :class="{ 'pages-page__table--filters-none': !showFilters }"
      v-if="rows"
      :ordering="ordering"
      :columns="columns"
      :rows="rows.data || []"
      :loading="loading"
      @update:ordering="handleOrdering"
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
      pagination
      :page="page"
      :first="savedFilters.first"
      :total="rows.paginatorInfo.total"
    >
      <template #header>
        <TableHeader
          :show-filters="showFilters"
          @update:showFilters="toggleShowFilters"
          :model-value="savedFilters"
          @update:model-value="handleFilters"
          placeholder="Поиск по названию и коду страницы"
        />
      </template>
      <template #col="{ col, row }">
        <div
          v-if="col.field === 'status_indicator'"
          :class="{
            'rows__indicator--green': row.is_published,
            'rows__indicator--gray': !row.is_published,
          }"
        >
          <span></span>
        </div>
        <router-link v-if="col.field === 'title'" :to="{ name: 'page-edit', params: { id: row.id } }">
          {{ row.title }}
        </router-link>
        <span v-if="col.field === 'slug'">{{ row.slug }}</span>
        <span v-if="col.field === 'kind'">{{ row.is_system ? "Системная" : "Обычная" }}</span>
        <span v-if="col.field === 'updated_at'">{{ formatDate(row.updated_at) }}</span>
        <span v-if="col.field === 'published_at'">{{ formatDate(row.published_at) }}</span>
        <span v-if="col.field === 'status'">
          {{ row.is_published ? "Опубликована" : "Не опубликована" }}
        </span>
        <div v-if="col.field === 'position'" class="pages-page__position">
          <button
            class="btn btn--sm btn--white"
            title="Поднять выше"
            :disabled="positionLoading === row.id"
            @click="movePosition(row, -1)"
          >
            <IconComponent name="arrow-up-line" />
          </button>
          <span>{{ row.position ?? "-" }}</span>
          <button
            class="btn btn--sm btn--white"
            title="Опустить ниже"
            :disabled="positionLoading === row.id"
            @click="movePosition(row, 1)"
          >
            <IconComponent name="arrow-down-line" />
          </button>
        </div>
        <div v-if="col.field === 'actions'" class="table-actions">
          <router-link
            :to="{ name: 'page-edit', params: { id: row.id } }"
            class="btn btn--sm btn--white"
            title="Просмотр и редактирование"
          >
            <IconComponent name="edit-line" />
          </router-link>
          <button
            v-if="!row.is_published"
            class="btn btn--sm"
            title="Опубликовать"
            :disabled="publishLoading === row.id"
            @click="publish(row)"
          >
            <IconComponent name="upload-cloud-line" />
          </button>
          <button
            v-else
            class="btn btn--sm btn--white"
            title="Снять с публикации"
            :disabled="publishLoading === row.id"
            @click="unpublish(row)"
          >
            <IconComponent name="eye-off-line" />
          </button>
          <button
            v-if="!row.is_system"
            class="btn btn--sm btn--danger"
            title="Удалить"
            @click="deletePage(row)"
          >
            <IconComponent name="delete-bin-line" />
          </button>
        </div>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import TableHeader from "@/views/pages/components/TableHeader.vue";
import IconComponent from "@/components/IconComponent.vue";
import { useFiltersStore } from "@/stores/pageFilters.js";
import { useMenuStore } from "@/stores/menu.js";
import PAGES from "@/graphql/queries/admin_pages_paginate.graphql";
import PAGE_DELETE from "@/graphql/mutations/PageDelete.graphql";
import PAGE_PUBLISH from "@/graphql/mutations/PagePublish.graphql";
import PAGE_UNPUBLISH from "@/graphql/mutations/PageUnpublish.graphql";
import PAGE_CHANGE_POSITION from "@/graphql/mutations/PageChangePosition.graphql";

export default {
  name: "PagesPage",
  inject: ["apollo"],
  components: {
    TableComponent,
    TableHeader,
    IconComponent,
  },

  async mounted() {
    await this.get();
  },

  data() {
    return {
      showFilters: false,
      loading: false,
      searchDebounce: null,
      page: 1,
      first: 25,
      publishLoading: null,
      positionLoading: null,
      // Пустой массив включает серверную сортировку по умолчанию
      // (position is null, position) — она совпадает с витриной и уводит
      // страницы без позиции в конец, а не в начало списка.
      ordering: [],
      rows: {
        data: [],
        paginatorInfo: {
          total: 0,
        },
      },
      columns: [
        { title: "", field: "status_indicator", show: true, width: "50px" },
        { title: "Название", field: "title", show: true },
        { title: "Код", field: "slug", show: true },
        { title: "Тип", field: "kind", show: true, width: "120px" },
        { title: "Дата публикации", field: "published_at", show: true },
        { title: "Изменена", field: "updated_at", show: true },
        { title: "Статус", field: "status", show: true },
        { title: "Порядок", field: "position", show: true, width: "150px" },
        { title: "Действия", field: "actions", show: true, width: "170px" },
      ],
    };
  },

  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Страницы");
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...(useFiltersStore().get(this.$route.name) || {
            search: null,
            is_published: null,
          }),
        },
      };
    },
  },

  methods: {
    toggleShowFilters($event) {
      this.showFilters = $event;
    },
    handleFilters($event) {
      if (this.searchDebounce) {
        clearTimeout(this.searchDebounce);
      }
      if (!this.loading) {
        this.searchDebounce = setTimeout(() => {
          useFiltersStore().set(this.$route.name, $event);
          this.page = 1;
          this.get();
        }, 500);
      }
    },
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.get();
    },
    handlePaginate($event) {
      this.page = $event;
      this.get();
    },
    handleOrdering($event) {
      this.ordering = $event;
      this.get();
    },

    async publish(page) {
      this.publishLoading = page.id;
      try {
        await this.apollo().admin.mutate({
          mutation: PAGE_PUBLISH,
          variables: { id: parseInt(page.id) },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при публикации страницы:", error);
        alert(this.getErrorMessage(error, "Не удалось опубликовать страницу"));
      } finally {
        this.publishLoading = null;
      }
    },

    async unpublish(page) {
      if (!confirm(`Снять страницу «${page.title}» с публикации?`)) {
        return;
      }
      this.publishLoading = page.id;
      try {
        await this.apollo().admin.mutate({
          mutation: PAGE_UNPUBLISH,
          variables: { id: parseInt(page.id) },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при снятии страницы с публикации:", error);
        alert(this.getErrorMessage(error, "Не удалось снять страницу с публикации"));
      } finally {
        this.publishLoading = null;
      }
    },

    async deletePage(page) {
      if (!confirm(`Удалить страницу «${page.title}»?`)) {
        return;
      }
      try {
        await this.apollo().admin.mutate({
          mutation: PAGE_DELETE,
          variables: { id: parseInt(page.id) },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при удалении страницы:", error);
        alert(this.getErrorMessage(error, "Не удалось удалить страницу"));
      }
    },

    async movePosition(page, delta) {
      const rows = this.rows.data || [];
      const index = rows.findIndex((item) => item.id === page.id);
      const targetIndex = index + delta;

      if (index < 0 || targetIndex < 0 || targetIndex >= rows.length) {
        return;
      }

      // Позиция считается по месту в общем списке, а не по номеру на странице.
      const offset = (this.page - 1) * this.savedFilters.first;
      const newPosition = offset + targetIndex + 1;

      this.positionLoading = page.id;
      try {
        await this.apollo().admin.mutate({
          mutation: PAGE_CHANGE_POSITION,
          variables: { id: parseInt(page.id), position: newPosition },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при изменении порядка страниц:", error);
        alert(this.getErrorMessage(error, "Не удалось изменить порядок страниц"));
      } finally {
        this.positionLoading = null;
      }
    },

    // Серверная ошибка (Lighthouse/GraphQL) лежит в error.graphQLErrors[0].message.
    // Показываем её пользователю, если она есть, иначе — запасной текст.
    getErrorMessage(error, fallback) {
      return error?.graphQLErrors?.[0]?.message || fallback;
    },

    async get() {
      if (this.loading) {
        return;
      }
      this.loading = true;

      const variables = {
        first: this.savedFilters.first,
        page: this.page,
        search: this.savedFilters.search,
        is_published: this.savedFilters.is_published,
        ordering: this.ordering,
      };

      this.rows.data = [];
      try {
        const { data } = await this.apollo().admin.query({
          query: PAGES,
          variables,
          fetchPolicy: "network-only",
        });

        if (data.admin_pages_paginate) {
          this.rows.data = data.admin_pages_paginate.data;
          this.rows.paginatorInfo = data.admin_pages_paginate.paginatorInfo;
        }
      } catch (error) {
        console.error("Ошибка при загрузке страниц:", error);
      } finally {
        this.loading = false;
      }
    },

    formatDate(dateString) {
      if (!dateString) return "-";
      const date = new Date(dateString);
      return date.toLocaleDateString("ru-RU", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      });
    },
  },
};
</script>

<style lang="stylus">
.pages-page {
  display flex
  flex-direction column
  gap 16px
  padding 16px 32px
  height 100%
  width 100%
  padding-bottom 0
  background var(--gray-3)

  &__table {
    max-height: calc(100vh - 220px)
    position static
  }

  &__table--filters-none {
    max-height calc(100vh - 172px)
  }

  &__header {
    display flex
    justify-content space-between
    align-items center

    h2 {
      margin 0
      font-weight 500
      font-size 1.5em
    }

    .btn {
      height 48px
    }
  }

  &__position {
    display flex
    align-items center
    gap 6px

    span {
      min-width 20px
      text-align center
    }

    .btn {
      padding 4px

      .icon {
        width 16px
        height 16px
      }
    }
  }
}
</style>
