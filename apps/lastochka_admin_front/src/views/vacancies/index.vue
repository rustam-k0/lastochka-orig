<template>
  <main class="vacancies-page">
    <div class="vacancies-page__header">
      <h2>{{ pageTitle }}</h2>
      <router-link :to="{ name: 'vacancy-create' }" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать вакансию</span>
      </router-link>
    </div>
    <TableComponent
      class="vacancies-page__table"
      :class="{ 'vacancies-page__table--filters-none': !showFilters }"
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
          :cities="cities"
          placeholder="Поиск по названию вакансии и городу"
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
        <router-link v-if="col.field === 'title'" :to="{ name: 'vacancy-edit', params: { id: row.id } }">
          {{ row.title }}
        </router-link>
        <span v-if="col.field === 'city'">{{ row.city || "-" }}</span>
        <span v-if="col.field === 'work_format'">
          {{ workFormatLabel(row.work_format) }}
        </span>
        <span v-if="col.field === 'employment_type'">
          {{ employmentTypeLabel(row.employment_type) }}
        </span>
        <span v-if="col.field === 'created_at'">{{ formatDate(row.created_at) }}</span>
        <span v-if="col.field === 'published_at'">{{ formatDate(row.published_at) }}</span>
        <span v-if="col.field === 'status'">
          {{ row.is_published ? "Опубликована" : "Не опубликована" }}
        </span>
        <div v-if="col.field === 'position'" class="vacancies-page__position">
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
            :to="{ name: 'vacancy-edit', params: { id: row.id } }"
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
          <button class="btn btn--sm btn--danger" title="Удалить" @click="deleteVacancy(row)">
            <IconComponent name="delete-bin-line" />
          </button>
        </div>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import TableHeader from "@/views/vacancies/components/TableHeader.vue";
import IconComponent from "@/components/IconComponent.vue";
import { useFiltersStore } from "@/stores/pageFilters.js";
import { useMenuStore } from "@/stores/menu.js";
import VACANCIES from "@/graphql/queries/admin_vacancies_paginate.graphql";
import VACANCY_CITIES from "@/graphql/queries/admin_vacancy_cities.graphql";
import VACANCY_DELETE from "@/graphql/mutations/VacancyDelete.graphql";
import VACANCY_PUBLISH from "@/graphql/mutations/VacancyPublish.graphql";
import VACANCY_UNPUBLISH from "@/graphql/mutations/VacancyUnpublish.graphql";
import VACANCY_CHANGE_POSITION from "@/graphql/mutations/VacancyChangePosition.graphql";
import { VACANCY_EMPLOYMENT_TYPE_LABELS, VACANCY_WORK_FORMAT_LABELS } from "@/constants/vacancies.js";

export default {
  name: "VacanciesPage",
  inject: ["apollo"],
  components: {
    TableComponent,
    TableHeader,
    IconComponent,
  },

  async mounted() {
    await this.get();
    await this.getCities();
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
      cities: [],
      ordering: [
        {
          orderColumn: "position",
          orderBy: "ASC",
        },
      ],
      rows: {
        data: [],
        paginatorInfo: {
          total: 0,
        },
      },
      columns: [
        { title: "", field: "status_indicator", show: true, width: "50px" },
        { title: "Наименование", field: "title", show: true },
        { title: "Город", field: "city", show: true },
        { title: "Формат работы", field: "work_format", show: true },
        { title: "Тип занятости", field: "employment_type", show: true },
        { title: "Дата создания", field: "created_at", show: true },
        { title: "Дата публикации", field: "published_at", show: true },
        { title: "Статус", field: "status", show: true },
        { title: "Порядок", field: "position", show: true, width: "150px" },
        { title: "Действия", field: "actions", show: true, width: "170px" },
      ],
    };
  },

  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Работа в компании");
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...(useFiltersStore().get(this.$route.name) || {
            search: null,
            is_published: null,
            city: null,
            work_format: null,
            employment_type: null,
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

    workFormatLabel(value) {
      return VACANCY_WORK_FORMAT_LABELS[value] || "-";
    },
    employmentTypeLabel(value) {
      return VACANCY_EMPLOYMENT_TYPE_LABELS[value] || "-";
    },

    async publish(vacancy) {
      this.publishLoading = vacancy.id;
      try {
        await this.apollo().admin.mutate({
          mutation: VACANCY_PUBLISH,
          variables: { id: parseInt(vacancy.id) },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при публикации вакансии:", error);
        alert("Не удалось опубликовать вакансию");
      } finally {
        this.publishLoading = null;
      }
    },

    async unpublish(vacancy) {
      if (!confirm(`Снять вакансию «${vacancy.title}» с публикации?`)) {
        return;
      }
      this.publishLoading = vacancy.id;
      try {
        await this.apollo().admin.mutate({
          mutation: VACANCY_UNPUBLISH,
          variables: { id: parseInt(vacancy.id) },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при снятии вакансии с публикации:", error);
        alert("Не удалось снять вакансию с публикации");
      } finally {
        this.publishLoading = null;
      }
    },

    async deleteVacancy(vacancy) {
      if (!confirm(`Удалить вакансию «${vacancy.title}»?`)) {
        return;
      }
      try {
        await this.apollo().admin.mutate({
          mutation: VACANCY_DELETE,
          variables: { id: parseInt(vacancy.id) },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при удалении вакансии:", error);
        alert("Не удалось удалить вакансию");
      }
    },

    async movePosition(vacancy, delta) {
      const rows = this.rows.data || [];
      const index = rows.findIndex((item) => item.id === vacancy.id);
      const targetIndex = index + delta;

      if (index < 0 || targetIndex < 0 || targetIndex >= rows.length) {
        return;
      }

      // Позиция считается по месту в общем списке, а не по номеру на странице.
      const offset = (this.page - 1) * this.savedFilters.first;
      const newPosition = offset + targetIndex + 1;

      this.positionLoading = vacancy.id;
      try {
        await this.apollo().admin.mutate({
          mutation: VACANCY_CHANGE_POSITION,
          variables: { id: parseInt(vacancy.id), position: newPosition },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при изменении порядка вакансий:", error);
        alert("Не удалось изменить порядок вакансий");
      } finally {
        this.positionLoading = null;
      }
    },

    async getCities() {
      try {
        const { data } = await this.apollo().admin.query({
          query: VACANCY_CITIES,
          fetchPolicy: "network-only",
        });
        this.cities = data.admin_vacancy_cities || [];
      } catch (error) {
        console.error("Ошибка при загрузке городов вакансий:", error);
      }
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
        city: this.savedFilters.city,
        work_format: this.savedFilters.work_format,
        employment_type: this.savedFilters.employment_type,
        ordering: this.ordering,
      };

      this.rows.data = [];
      try {
        const { data } = await this.apollo().admin.query({
          query: VACANCIES,
          variables,
          fetchPolicy: "network-only",
        });

        if (data.admin_vacancies_paginate) {
          this.rows.data = data.admin_vacancies_paginate.data;
          this.rows.paginatorInfo = data.admin_vacancies_paginate.paginatorInfo;
        }
      } catch (error) {
        console.error("Ошибка при загрузке вакансий:", error);
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
.vacancies-page {
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
