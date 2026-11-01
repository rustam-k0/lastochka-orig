<template>
  <main class="main">
    <BreadcrumbsComponent title="Тест" :links="breadcrumbs" />
    <div class="page">
      <div class="container list">
        <TableComponent
          :columns="columns"
          :initialSortBy="vgtFormattedOrdering"
          @sort="changeSort"
          :page="page"
          :first="first"
          :rows="list.data"
          :total="list.paginatorInfo.total"
          @change="changeFirst"
          @paginate="changePage"
          pagination
        >
          <div slot="empty" class="td-container">
            <span class="table-span">
              <LoadingIndicator v-if="loading" />
              <template v-else>Данных нет</template>
            </span>
          </div>
          <template slot="table-row" slot-scope="{ row, column }">
            <span v-if="column.field === 'title'" class="td-container">
              <span class="table-span">{{ row["title"] }}</span>
            </span>
            <span v-if="column.field === 'type'" class="td-container">
              <span class="table-span">{{ row["status"] ? row["status"]["name"] : "" }}</span>
            </span>
            <span v-if="column.field === 'status'" class="td-container">
              <span class="table-span">{{ row["status"] ? row["status"]["name"] : "" }}</span>
            </span>
            <span v-if="column.field === 'updated_at'" class="td-container">
              <span class="table-span">{{ row["updated_at"] | formatDate }}</span>
            </span>
            <div v-if="column.field === 'actions'" class="table-action table-action--main">
              <button type="button" @click="showModal('HomeModal', row)">Показать модалку</button>
              <Icon category="system" name="arrow-down-s-line" />
              <div class="table-action__drop">
                <a
                  @click.prevent="changeStatus(row.id, row.status.code === 1 ? 3 : 1, row.status.table_name)"
                >
                  <span v-if="row.status.code === 1">Скрыть</span>
                  <span v-else>Опубликовать</span>
                </a>
                <a @click.prevent="changeStatus(row.id, 2, row.status.table_name)">Удалить</a>
              </div>
            </div>
          </template>
        </TableComponent>
      </div>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/Breadcrumbs";
import TableComponent from "@/components/TableComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import Icon from "@/components/IconComponent.vue";
import GET from "@/graphql/generated/test/join_data.graphql";

export default {
  name: "TestListPage",
  data() {
    return {
      loading: false,
      breadcrumbs: [],
      columns: [
        { label: "Заголовок", field: "title" },
        { label: "Тип", field: "type" },
        { label: "Статус", field: "status" },
        { label: "Дата", field: "updated_at" },
        { field: "actions", width: "200px", sortable: false },
      ],
      ordering: [{ orderColumn: "updated_at", orderBy: "DESC" }],
      list: { data: [], paginatorInfo: { total: 0 } },
      page: 1,
      first: 20,
    };
  },
  computed: {
    vgtFormattedOrdering() {
      return this.ordering.map((o) => ({
        field: o.orderColumn,
        type: o.orderBy.toLowerCase(),
      }));
    },
  },
  mounted() {
    this.submit();
    // const urlParams = new URLSearchParams(window.location.search);
    // history.replaceState(null, "", location.pathname + urlParams);
  },
  methods: {
    submit() {
      if (!this.loading) {
        this.loading = true;
        this.list.data = [];
        let variables = { first: this.first, page: this.page, ordering: this.ordering };

        this.$apollo
          .query({
            query: GET,
            variables: variables,
          })
          .then(({ data }) => {
            if (data && data["join_data"]) {
              this.list = data["join_data"];
            }
            this.loading = false;
          })
          .catch(() => {});
      }
    },
    changeSort(event) {
      this.ordering = [];
      event.forEach((column) => {
        if (column.type.toUpperCase() !== "NONE") {
          this.ordering.push({
            orderColumn: column.field,
            orderBy: column.type.toUpperCase(),
          });
        }
      });
      this.submit();
    },
    changeStatus(id, status, table) {
      if (!this.loading) {
        this.loading = true;
        this.$store
          .dispatch("CHANGE_STATUS", {
            apollo: this.$apollo,
            variables: {
              items: [
                {
                  id: id,
                  code: status,
                  table_name: table,
                },
              ],
            },
          })
          .then(({ data }) => {
            if (data && data.ChangeStatus) {
              this.$notify({
                title: "Готово",
                text: data.ChangeStatus.message,
                type: "success",
              });
            }
            this.$nextTick(this.submit);
            this.loading = false;
          })
          .catch(() => {
            this.loading = false;
          });
      }
    },
    showModal(name, object) {
      this.$store.state._modals.push({
        component: () => import(`@/components/modals/components/generated/${name}.vue`),
        options: {
          object: object,
        },
      });
    },
    changePage(e) {
      this.page = e;
      this.submit();
    },
    changeFirst(e) {
      this.first = e.id;
      this.submit();
    },
  },
  components: {
    LoadingIndicator,
    TableComponent,
    BreadcrumbsComponent,
    Icon,
  },
};
</script>
