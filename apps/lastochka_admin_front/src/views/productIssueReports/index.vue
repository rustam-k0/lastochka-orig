<template>
  <main class="issue-reports-page page--main">
    <div class="page__header">
      <h2>{{ pageTitle }}</h2>
    </div>

    <!-- Фильтр по типу -->
    <div class="issue-reports-page__filters">
      <div class="issue-reports-page__filter-buttons">
        <button
          v-for="filter in typeFilters"
          :key="filter.value"
          class="issue-reports-page__filter-btn"
          :class="{
            'issue-reports-page__filter-btn--active':
              typeFilter === filter.value,
          }"
          @click="setTypeFilter(filter.value)"
        >
          {{ filter.label }}
        </button>
      </div>
    </div>

    <TableComponent
      v-if="rows"
      class="issue-reports-page__table"
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
        <span v-if="col.field === 'client'">{{
          row.client?.name || row.email
        }}</span>
        <span v-if="col.field === 'email'">{{ row.email }}</span>
        <template v-if="col.field === 'type'">
          <div
            v-if="row.type === 'description_error'"
            class="status status--orange"
          >
            <span>Ошибка в описании</span>
          </div>
          <div
            v-else-if="row.type === 'missing_characteristics'"
            class="status status--blue"
          >
            <span>Нет характеристик</span>
          </div>
          <span v-else>{{ row.type }}</span>
        </template>
        <span v-if="col.field === 'message'">{{ row.message }}</span>
        <span v-if="col.field === 'shop'">{{ row.shop?.title || "—" }}</span>
        <span v-if="col.field === 'created_at'">{{ row.created_at }}</span>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import PRODUCT_ISSUE_REPORTS from "@/graphql/pages/ProductIssueReports/productIssueReports.graphql";
import { useFiltersStore } from "@/stores/pageFilters.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "ProductIssueReportsPage",
  inject: ["apollo"],
  components: {
    TableComponent,
  },

  data() {
    return {
      loading: false,
      first: 25,
      page: 1,
      typeFilter: null,
      typeFilters: [
        { value: null, label: "Все" },
        { value: "description_error", label: "Ошибки в описании" },
        { value: "missing_characteristics", label: "Нет характеристик" },
      ],
      columns: [
        {
          title: "ID",
          field: "id",
          show: true,
          width: "70px",
        },
        {
          title: "Товар",
          field: "product",
          show: true,
        },
        {
          title: "Клиент",
          field: "client",
          show: true,
          width: "160px",
        },
        {
          title: "Email",
          field: "email",
          show: true,
          width: "200px",
        },
        {
          title: "Тип",
          field: "type",
          show: true,
          width: "170px",
        },
        {
          title: "Сообщение",
          field: "message",
          show: true,
        },
        {
          title: "Магазин",
          field: "shop",
          show: true,
          width: "140px",
        },
        {
          title: "Дата",
          field: "created_at",
          show: true,
          width: "160px",
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
    setTypeFilter(value) {
      this.typeFilter = value;
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
          query: PRODUCT_ISSUE_REPORTS,
          variables: {
            page: this.page,
            first: this.savedFilters.first,
            type: this.typeFilter || undefined,
          },
          fetchPolicy: "network-only",
        });
        if (data && data.productIssueReports) {
          this.rows.data = data.productIssueReports.data;
          this.rows.paginatorInfo = data.productIssueReports.paginatorInfo;
        }
      } catch (error) {
        console.error("Error fetching product issue reports:", error);
      } finally {
        this.loading = false;
      }
    },
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Обращения по товарам");
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
.issue-reports-page {
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
}

.status--blue {
  background rgba(66, 133, 244, 0.1)
  color #4285F4
  padding 4px 10px
  border-radius 6px
  font-size 0.8125rem
  display inline-block
}
</style>
