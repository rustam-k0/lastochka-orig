<template>
  <main class="main">
    <BreadcrumbsComponent title="Все материалы" :links="breadcrumbs" />
    <div class="page">
      <div class="container list">
        <FiltersRow :loading="loading" @reset="reset" @submit="submit">
          <InputComponent
            v-model.trim="form['title'].value"
            :errors="form['title'].errors"
            title="Заголовок"
            autofocus
          />
          <SelectComponent
            v-model.trim="form['type'].value"
            :errors="form['type'].errors"
            title="Тип"
            :options="typeOptionsArray"
            label-name="name"
            id-name="id"
          />
          <SelectComponent
            v-model.trim="form['status'].value"
            :errors="form['status'].errors"
            title="Статус"
            :options="statusOptionsArray"
            label-name="name"
            id-name="id"
          />
          <DateComponent
            v-model.trim="form['updated_at'].value"
            :errors="form['updated_at'].errors"
            title="Дата обновления"
            placeholder="Не выбрано"
            range
            mode="dateTime"
          />
        </FiltersRow>
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
            <router-link
              v-if="column.field === 'title'"
              :to="{ name: row.status.route, params: { id: row.id } }"
              class="td-container"
            >
              <span class="table-span">{{ row["title"] }}</span>
            </router-link>
            <router-link
              v-if="column.field === 'type'"
              :to="{ name: row.status.route, params: { id: row.id } }"
              class="td-container"
            >
              <span class="table-span">{{ row["status"] ? row["status"]["type"] : "" }}</span>
            </router-link>
            <router-link
              v-if="column.field === 'status'"
              :to="{ name: row.status.route, params: { id: row.id } }"
              class="td-container"
            >
              <span class="table-span">{{ row["status"] ? row["status"]["name"] : "" }}</span>
            </router-link>
            <router-link
              v-if="column.field === 'updated_at'"
              :to="{ name: row.status.route, params: { id: row.id } }"
              class="td-container"
            >
              <span class="table-span">{{ row["updated_at"] | formatDate }}</span>
            </router-link>
            <div v-if="column.field === 'actions'" class="table-action table-action--main">
              <router-link :to="{ name: row.status.route, params: { id: row.id } }">
                Редактировать
              </router-link>
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
import FiltersRow from "@/components/FiltersRow.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/index.vue";
import DateComponent from "@/components/inputs/DateComponent.vue";
import moment from "moment";
import GET from "@/graphql/generated/home/join_data.graphql";

export default {
  name: "HomeListPage",
  data() {
    return {
      loading: false,
      typeOptionsArray: [{ id: "test_entities", name: "Тестовая запись" }],
      statusOptionsArray: [
        { id: 1, name: "Активный" },
        { id: 3, name: "Неопубликованный" },
      ],
      form: {
        title: { value: null, defaultValue: null, errors: [] },
        type: { value: null, defaultValue: null, errors: [] },
        status: { value: null, defaultValue: null, errors: [] },
        updated_at: { value: null, defaultValue: null, errors: [] },
      },
      breadcrumbs: [],
      columns: [
        { label: "Заголовок", field: "title" },
        { label: "Тип", field: "type" },
        { label: "Статус", field: "status" },
        { label: "Дата", field: "updated_at" },
        { field: "actions", width: "150px", sortable: false },
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
        Object.keys(this.form).forEach((k) => {
          variables[k] = this.form[k].value !== null ? this.form[k].value : undefined;
        });
        variables["type"] = undefined;
        if (this.form["type"].value) {
          variables["type"] = this.form["type"].value["id"];
        }
        variables["status"] = undefined;
        if (this.form["status"].value) {
          variables["status"] = this.form["status"].value["id"];
        }
        variables["updated_at"] = undefined;
        if (this.form["updated_at"].value) {
          variables["updated_at_start_date"] = moment(this.form["updated_at"].value.start).format(
            "YYYY-MM-DD HH:mm:ss"
          );
          variables["updated_at_end_date"] = moment(this.form["updated_at"].value.end).format(
            "YYYY-MM-DD HH:mm:ss"
          );
        }

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
    reset() {
      Object.keys(this.form).forEach((key) => {
        this.form[key].value = this.form[key].defaultValue;
        this.form[key].errors = [];
      });
      this.submit();
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
    FiltersRow,
    InputComponent,
    SelectComponent,
    DateComponent,
  },
};
</script>
