<template>
  <main class="preorders-page page--main">
    <div class="page__header">
      <h2>{{ pageTitle }}</h2>
    </div>

    <!-- Фильтр по статусу -->
    <div class="preorders-page__filters">
      <div class="preorders-page__filter-buttons">
        <button
          v-for="filter in statusFilters"
          :key="filter.value"
          class="preorders-page__filter-btn"
          :class="{
            'preorders-page__filter-btn--active': statusFilter === filter.value,
          }"
          @click="setStatusFilter(filter.value)"
        >
          {{ filter.label }}
        </button>
      </div>
    </div>

    <TableComponent
      v-if="rows"
      class="preorders-page__table"
      :columns="columns"
      :rows="rows.data || []"
      :loading="loading"
      :total="rows.paginatorInfo.total"
      :first="savedFilters.first"
      :page="page"
      pagination
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
    >
      <template #col="{ col, row }">
        <span v-if="col.field === 'id'">{{ row.id }}</span>
        <span v-if="col.field === 'product'">{{
          row.product?.title || "—"
        }}</span>
        <span v-if="col.field === 'full_name'">{{ row.full_name }}</span>
        <span v-if="col.field === 'phone'">{{ row.phone }}</span>
        <span v-if="col.field === 'quantity'">{{ row.quantity }}</span>
        <template v-if="col.field === 'status'">
          <div v-if="row.status === 'new'" class="status status--orange">
            <span>Новая</span>
          </div>
          <div
            v-else-if="row.status === 'completed'"
            class="status status--green"
          >
            <span>Выполнена</span>
          </div>
          <div
            v-else-if="row.status === 'cancelled'"
            class="status status--red"
          >
            <span>Отменена</span>
          </div>
          <span v-else>{{ row.status }}</span>
        </template>
        <span v-if="col.field === 'created_at'">{{ row.created_at }}</span>
        <template v-if="col.field === 'actions'">
          <div v-if="row.status === 'new'" class="preorders-page__actions">
            <button
              class="btn btn--sm btn--green"
              @click="updateStatus(row.id, 'completed')"
              :disabled="updatingId === row.id"
            >
              Выполнить
            </button>
            <button
              class="btn btn--sm btn--red"
              @click="updateStatus(row.id, 'cancelled')"
              :disabled="updatingId === row.id"
            >
              Отменить
            </button>
          </div>
        </template>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import PREORDERS from "@/graphql/pages/Preorders/preorders.graphql";
import { useFiltersStore } from "@/stores/pageFilters.js";
import PREORDER_UPDATE_STATUS from "@/graphql/mutations/PreorderUpdateStatus.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "PreordersPage",
  inject: ["apollo"],
  components: {
    TableComponent,
  },

  data() {
    return {
      loading: false,
      updatingId: null,
      first: 25,
      page: 1,
      statusFilter: null,
      statusFilters: [
        { value: null, label: "Все" },
        { value: "new", label: "Новые" },
        { value: "completed", label: "Выполненные" },
        { value: "cancelled", label: "Отменённые" },
      ],
      columns: [
        {
          title: "ID",
          field: "id",
          show: true,
          width: "80px",
        },
        {
          title: "Товар",
          field: "product",
          show: true,
        },
        {
          title: "ФИО",
          field: "full_name",
          show: true,
        },
        {
          title: "Телефон",
          field: "phone",
          show: true,
        },
        {
          title: "Кол-во",
          field: "quantity",
          show: true,
          width: "90px",
        },
        {
          title: "Статус",
          field: "status",
          show: true,
          width: "130px",
        },
        {
          title: "Дата",
          field: "created_at",
          show: true,
          width: "160px",
        },
        {
          title: "Действия",
          field: "actions",
          show: true,
          system: true,
          width: "220px",
        },
      ],
      rows: {
        data: [],
        paginatorInfo: {
          total: 0,
        },
      },
    };
  },

  mounted() {
    this.get();
  },

  methods: {
    setStatusFilter(value) {
      this.statusFilter = value;
      this.page = 1;
      this.get();
    },
    handlePaginate($event) {
      this.page = $event;
      this.get();
    },
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.get();
    },
    async get() {
      if (this.loading) return;
      this.rows.data = [];
      this.loading = true;
      try {
        const { data } = await this.apollo().admin.query({
          query: PREORDERS, // Ошибка в graphql scheme
          variables: {
            page: this.page,
            first: this.savedFilters.first,
            status: this.statusFilter || undefined,
          },
          fetchPolicy: "network-only",
        });
        if (data && data.preorders) {
          this.rows.data = data.preorders.data;
          this.rows.paginatorInfo = data.preorders.paginatorInfo;
        }
      } catch (error) {
        console.error("Error fetching preorders:", error);
      } finally {
        this.loading = false;
      }
    },
    async updateStatus(id, status) {
      this.updatingId = id;
      try {
        await this.apollo().admin.mutate({
          mutation: PREORDER_UPDATE_STATUS,
          variables: {
            id: parseInt(id),
            status: status,
          },
        });
        await this.get();
      } catch (error) {
        console.error("Error updating preorder status:", error);
        alert("Ошибка обновления статуса заявки");
      } finally {
        this.updatingId = null;
      }
    },
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, 'Заявки "Под заказ"');
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...useFiltersStore().get(this.$route.name),
        },
      };
    },
  },
};
</script>

<style lang="stylus">
.preorders-page {
  display flex
  flex-direction column
  gap 16px
  padding 16px 32px
  height 100%
  width 100%
  padding-bottom 0
  background var(--gray-3)

  &__table {
    max-height: calc(100vh - 200px);

    .table__container {
      height: calc(100% - 70px) !important;
    }
  }

  &__filters {
    display flex
    align-items center
    gap 16px
  }

  &__filter-buttons {
    display flex
    gap 8px
  }

  &__filter-btn {
    padding 10px 20px
    border 1px solid var(--gray-dark)
    border-radius 8px
    background var(--white)
    font-size 0.875rem
    color var(--dark)
    cursor pointer
    transition all 0.2s

    &:hover {
      border-color var(--pink)
    }

    &--active {
      background var(--pink)
      border-color var(--pink)
      color var(--white)
    }
  }

  &__actions {
    display flex
    gap 8px
  }
}
</style>
