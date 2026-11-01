<template>
  <main class="page--main staff-page">
    <div class="page__header">
      <h2>{{ pageTitle }}</h2>
      <button v-if="activeTab === 'staff'" @click="openModal" class="btn btn--sm">
        <IconComponent name="add-circle-line" />
        <span>Новый сотрудник</span>
      </button>
    </div>

    <div v-if="assemblyZonesEnabled" class="staff-page__tabs">
      <button
        class="staff-page__tab"
        :class="{ 'staff-page__tab--active': activeTab === 'staff' }"
        @click="activeTab = 'staff'"
      >
        Сотрудники
      </button>
      <button
        class="staff-page__tab"
        :class="{ 'staff-page__tab--active': activeTab === 'zones' }"
        @click="activeTab = 'zones'"
      >
        Зоны сборки
      </button>
    </div>

    <StaffAssemblyZones v-if="activeTab === 'zones' && assemblyZonesEnabled" />

    <TableComponent
      v-else-if="rows"
      :ordering="ordering"
      :columns="(columns || []).filter((c) => c.show || c.system)"
      :rows="rows.data || []"
      :selectable="false"
      :total="rows.paginatorInfo.total"
      @update:page="handlePaginate"
      :first="savedFilters.first"
      @update:count="handleFirstCount"
      :page="page"
      :loading="loading"
      :paginationThreshold="0"
      pagination
    >
      <template #header>
        <StaffTableHeader v-model="search" @update:modelValue="handleSearch" />
      </template>
      <template #col="{ col, row }">
        <!--        <div v-if="col.field === 'standing'" class="tabs__col-standing">-->
        <!--          <IconComponent name="dot" />-->
        <!--          {{ row.standing }}-->
        <!--        </div>-->
        <div v-if="col.field === 'id'">
          <span>{{ row.id }}</span>
        </div>
        <div v-if="col.field === 'name'">
          <span>{{ row.name }} {{ row.surname }}</span>
        </div>
        <div v-if="col.field === 'role'">
          <span>{{ row.roles[0].name_ru }}</span>
        </div>
        <a
          :href="'mailto:' + row.email"
          v-if="col.field === 'email'"
          class="tabs__col-email"
        >
          {{ row.email }}
        </a>
        <button v-if="col.field === 'action'" class="btn staff-page__action">
          <span @click="openModal($event, row.id)">Редактировать</span>
          <!--          <IconComponent name="more-2-fill" />-->
          <!--          <ActionMenu @update="openModal($event, row.id)" @remove="remove(row.id)" />-->
        </button>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import TableComponent from "@/components/table/index.vue";
import NewStaffModal from "@/views/staff/modals/NewStaffModal.vue";
import { useDefaultStore } from "@/stores/default.js";
import { useFiltersStore } from "@/stores/pageFilters.js";
import StaffTableHeader from "@/views/staff/components/StaffTableHeader.vue";
import StaffAssemblyZones from "@/views/staff/components/StaffAssemblyZones.vue";
import { shallowRef } from "vue";
import GET from "@/graphql/pages/Staffs/users_paginate.graphql";
import ActionMenu from "@/components/ActionMenu.vue";
import REMOVE from "@/graphql/mutations/EntityDelete.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { useMenuStore } from "@/stores/menu.js";
export default {
  name: "StaffPage",
  inject: ["apollo"],
  data() {
    return {
      activeTab: "staff",
      loading: false,
      first: 25,
      page: 1,
      search: "",
      searchTimeout: null,
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
          title: "ID",
          field: "id",
          // sortable: true,
          selectable: true,
          show: true,
          width: "80px",
        },
        {
          title: "Имя",
          field: "name",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "272px",
        },
        {
          title: "Должность",
          field: "role",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "272px",
        },
        // {
        //   title: "Статус",
        //   field: "standing",
        //   // sortable: true,
        //   selectable: true,
        //   show: true,
        //   // width: "160px",
        // },
        {
          title: "Email",
          field: "email",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "272px",
        },
        {
          title: "",
          field: "action",
          width: "180px",
          // sortable: true,
          selectable: true,
          show: true,
        },
        // {
        //   title: "Количество заказов",
        //   field: "orders",
        //   // sortable: true,
        //   selectable: true,
        //   show: true,
        //   // width: "272px",
        // },
        // {
        //   title: "Средний чек",
        //   field: "average_check",
        //   show: true,
        //   system: true,
        //   // width: "160px",
        // },
        // {
        //   title: "Сумма продаж",
        //   field: "sales_amount",
        //   show: true,
        //   system: true,
        //   // width: "160px",
        // },
      ],
    };
  },
  mounted() {
    this.get();
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Сотрудники");
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...useFiltersStore().get(this.$route.name),
        },
      };
    },
    assemblyZonesEnabled() {
      // Вкладка доступна только при включённом модуле И видимом пункте меню
      // «Зоны сборки» (тогглер в Настройки → Разделы меню)
      return (
        useModuleConfigStore().isModuleEnabled("assembly_zones") && useMenuStore().isVisible("assembly-zones")
      );
    },
  },
  methods: {
    get() {
      this.loading = true;
      let variables = {
        first: this.savedFilters.first,
        page: this.page,
        ordering: this.ordering,
        search: this.search || undefined,
      };
      this.apollo()
        .admin.query({
          query: GET,
          variables: variables,
        })
        .then(({ data }) => {
          if (data.users_paginate) {
            this.rows.data = data.users_paginate.data;
            this.rows.paginatorInfo = data.users_paginate.paginatorInfo;
          }
          this.loading = false;
        })
        .catch(() => {
          this.loading = false;
          console.log("error");
        });
    },
    remove(id) {
      if (confirm("Вы уверены?")) {
        this.apollo()
          .admin.mutate({
            mutation: REMOVE,
            variables: {
              id: id,
              table: "users",
            },
          })
          .then(() => {
            this.get();
          });
      }
    },
    handleSearch() {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        this.page = 1;
        this.get();
      }, 300);
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
    openModal($event, id) {
      useDefaultStore().modals.push({
        component: shallowRef(NewStaffModal),
        options: {
          id: id || undefined,
          callback: this.get,
        },
      });
    },
  },

  components: {
    ActionMenu,
    StaffTableHeader,
    StaffAssemblyZones,
    TableComponent,
    IconComponent,
  },
};
</script>

<style lang="stylus">
.tabs {
  &__col-standing {
    display flex
    gap 6px
    align-items: center;
    font-weight: 500;
    font-size: 0.75em;
    line-height: 16px;
    color var(--green)
    padding 4px
    background-color var(--green-50)
    border-radius: 6px;
  }

  &__col-email {
    display: flex;
    align-items: center;
    height: 100%;
    color var(--blue-600)
  }
}
.staff-page__tabs {
  display flex
  gap 8px
  margin-bottom 20px
  border-bottom 1px solid var(--white-2)
}
.staff-page__tab {
  padding 10px 4px
  background transparent
  border none
  border-bottom 2px solid transparent
  color var(--gray-2)
  cursor pointer
  font-size 0.9375rem

  &--active {
    color var(--dark)
    border-bottom-color var(--blue-600)
    font-weight 500
  }
}
.staff-page__action {
  padding: 8px
  background var(--white)
  border: 1px solid var(--neutral-100)
  border-radius: 6px;
  display flex
  color: var(--dark)
  position relative
  cursor: pointer;

  &:hover .action-menu {
    visibility visible
    opacity: 1;
  }

  & .icon {
    width 16px
    height 16px
  }
}
</style>
