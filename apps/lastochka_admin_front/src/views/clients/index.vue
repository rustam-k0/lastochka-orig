<template>
  <main class="page clients-page">
    <div class="clients-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="clients-page__btn">
        <button @click="openAddModal" class="btn btn--lg">
          <IconComponent name="add-circle-line" />
          <span> Новый клиент </span>
        </button>
      </div>
    </div>
    <TableComponent
      v-if="rows"
      :ordering="ordering"
      :columns="(columns || []).filter((c) => c.show || c.system)"
      :rows="rows.data || []"
      @update:selected="handleSelected"
      @update:ordering="handleOrdering"
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
      :total="rows.paginatorInfo.total"
      :first="savedFilters.first"
      :page="page"
      :loading="loading"
      class="clients-page-table"
      pagination
    >
      <template #header>
        <ClientsTableHeader
          :model-value="savedFilters"
          @update:model-value="handleFilters"
        />
      </template>
      <template #col="{ col, row }">
        <router-link
          :to="{ name: 'client', params: { id: row.id } }"
          v-if="col.field === 'fio'"
          class="tabs__col-content"
        >
          <span>{{ fullName(row) }}</span>
          <span
            v-if="isB2bModuleEnabled && row.is_b2b"
            class="badge badge--b2b"
          >B2B</span>
        </router-link>
        <router-link
          :to="{ name: 'client', params: { id: row.id } }"
          v-if="col.field === 'phone'"
          class="rows__phone"
        >
          <span>
            {{ row.phone ? mask(row.phone, "#-(###)-###-##-##") : "" }}
          </span>
        </router-link>
        <router-link
          :to="{ name: 'client', params: { id: row.id } }"
          v-if="col.field === 'orders_count'"
          class="rows__left"
        >
          {{ row.orders_count }}
        </router-link>
        <router-link
          :to="{ name: 'client', params: { id: row.id } }"
          v-if="col.field === 'orders_amount'"
          class="rows__left"
        >
          {{ formatPrice(row.orders_amount) }}
        </router-link>
        <router-link
          :to="{ name: 'client', params: { id: row.id } }"
          v-if="col.field === 'email'"
          class="rows__left"
        >
          <span class="rows__email">
            {{ row.email }}
          </span>
        </router-link>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import IconComponent from "@/components/IconComponent.vue";
import { useDefaultStore } from "@/stores/default.js";
import AddClientModal from "@/views/clients/modals/AddClientModal.vue";
import { shallowRef } from "vue";
import ClientsTableHeader from "@/views/clients/components/ClientsTableHeader.vue";
import CLIENTS from "@/graphql/pages/Clients/admin_clients_paginate.graphql";
import { useFiltersStore } from "@/stores/pageFilters.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { formatPrice } from "@/utils.js";
import { mask } from "maska";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "ClientsPage",
  inject: ["apollo"],
  components: {
    ClientsTableHeader,
    TableComponent,
    IconComponent,
  },
  mounted() {
    this.get();
  },
  data() {
    return {
      loading: false,
      searchDebounce: false,
      first: 25,
      page: 1,
      ordering: [
        {
          orderColumn: "created_at",
          orderBy: "DESC",
        },
      ],
      rows: {
        data: [],
        paginatorInfo: {
          total: 0,
        },
      },
      columns: [
        {
          title: "ФИО",
          field: "fio",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Номер телефона",
          field: "phone",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Кол-во заказов",
          field: "orders_count",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Сумма покупок",
          field: "orders_amount",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Почта",
          field: "email",
          // sortable: true,
          selectable: true,
          show: true,
        },
      ],
    };
  },
  methods: {
    fullName(row) {
      return [row.surname, row.name, row.patronymic].filter(Boolean).join(" ");
    },
    handleSelected($event) {
      this.selected = [...$event];
    },
    openAddModal() {
      useDefaultStore().modals.push({
        component: shallowRef(AddClientModal),
        options: {
          callback: this.get,
        },
      });
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
    handleSearch($event) {
      console.log($event);
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
    },
    mask,
    formatPrice,
    get() {
      if (!this.loading) {
        this.loading = true;
        let variables = {
          page: this.page,
          first: this.savedFilters.first,
          ordering: this.ordering,
          title: this.savedFilters.search,
          is_b2b: this.isB2bModuleEnabled ? this.savedFilters.is_b2b ?? null : null,
        };
        this.rows.data = [];
        this.apollo()
          .admin.query({
            query: CLIENTS,
            variables,
          })
          .then(({ data }) => {
            if (data.admin_clients_paginate) {
              this.rows.data = data.admin_clients_paginate.data;
              this.rows.paginatorInfo =
                data.admin_clients_paginate.paginatorInfo;
              this.loading = false;
              // this.removing = null;
            }
          })
          .catch(() => {
            this.loading = false;
            // this.removing = null;
          });
      }
    },
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Клиенты");
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...(useFiltersStore().get(this.$route.name) || {
            search: null,
            market: null,
            is_b2b: null,
          }),
        },
      };
    },
    isB2bModuleEnabled() {
      return useModuleConfigStore().isModuleEnabled("b2b");
    },
  },
};
</script>

<style lang="stylus">
.clients-page {

  &__header {
    display flex
    justify-content space-between
    align-items center

    h2 {
      margin 0
      font-family var(--font-bold)
      font-weight 500
      font-size 1.5rem
    }
  }

  &__btn {
    display flex
    gap 10px

    & .btn {
      padding: 14px 26.5px
    }
  }
  & .table {
    //max-height: none

    &__header {
      padding: 16px
    }
  }
}

.clients-page-table {
  height: calc(100vh - 243px);

  //& .table__container {
  //  min-height: calc(100vh - 298px);
  //}
  //
  //& .tabs__col-content {
  //  font-weight: 400;
  //  font-size: 0.813rem
  //  line-height: 20px;
  //  color: var(--dark)
  //}
  //
  //&__last-sum {
  //  font-weight: 500;
  //}
  //
  //&__create {
  //  color var(--gray-6)
  //}
  //
  //&__email {
  //  text-decoration-line: underline;
  //  color: var(--blue-2);
  //}
  //
  //&__action {
  //  width 20px
  //  height: 20px;
  //  cursor: pointer;
  //}
}
</style>
