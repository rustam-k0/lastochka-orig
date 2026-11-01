<template>
  <main class="page stores-page">
    <div class="page__header">
      <h2>{{ pageTitle }}</h2>
      <router-link v-if="isAdmin" :to="{ name: 'store' }" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span> Добавить </span>
      </router-link>
    </div>

    <TableComponent
      class="stores-page__table"
      v-if="rows"
      :ordering="ordering"
      :columns="(columns || []).filter((c) => c.show || c.system)"
      :rows="rows.data || []"
      :selectable="false"
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
        <StoresTableHeader
          :model-value="savedFilters"
          @update:model-value="handleFilters"
        />
      </template>
      <template #col="{ col, row }">
        <div v-if="col.field === 'logo_img'" class="rows__img">
          <ImgComponent :head_img="row.logo_img" />
        </div>
        <router-link
          :to="{ name: 'store', params: { id: row.id } }"
          v-if="col.field === 'title'"
          class="rows__left"
        >
          <span>{{ row.title }}</span>
        </router-link>
        <div v-if="col.field === 'address'" class="rows__left">
          {{ row.address }}
        </div>
        <div v-if="col.field === 'schedule'" class="rows__left">
          {{ row.schedule }}
        </div>
        <div v-if="col.field === 'active'" style="padding-left: 17px">
          <FlagCheckboxComponent
            :model-value="row.status_id === 1"
            :disabled="!isAdmin"
            @update:model-value="entityHideOrPublish(row.id)"
          />
        </div>
        <div v-if="col.field === 'action' && isAdmin" class="stores-page__action">
          <IconComponent name="more-2-fill" />
          <ActionMenu
            @update="routeToUpdateShop(row.id)"
            @remove="remove(row.id)"
          />
        </div>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import TableComponent from "@/components/table/index.vue";
import ActionMenu from "@/components/ActionMenu.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import GET from "@/graphql/pages/Stores/shops_paginate.graphql";
import dayjs from "dayjs";
import { useFiltersStore } from "@/stores/pageFilters.js";
import REMOVE from "@/graphql/mutations/ShopDelete.graphql";
import ImgComponent from "@/components/ImgComponent.vue";
import ENTITY_HIDE_OR_PUBLISH from "@/graphql/mutations/EntityHideOrPublish.graphql";
import { useMenuStore } from "@/stores/menu.js";
import StoresTableHeader from "@/views/stores/components/StoresTableHeader.vue";
import { useAuthStore } from "@/stores/auth.js";

export default {
  name: "StoresPage",
  inject: ["apollo"],

  mounted() {
    this.get();
  },

  data() {
    return {
      loading: false,
      page: 1,
      first: 25,
      filtersDebounce: null,
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
          title: "Фото",
          field: "logo_img",
          // sortable: true,
          selectable: true,
          width: "80px",
          show: true,
        },
        {
          title: "Название",
          field: "title",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Адрес",
          field: "address",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "График работы",
          field: "schedule",
          // sortable: true,
          selectable: true,
          width: "350px",
          show: true,
        },
        {
          title: "Статус",
          field: "active",
          // sortable: true,
          selectable: true,
          show: true,
          width: "120px",
        },
        {
          title: "",
          field: "action",
          width: "64px",
          // sortable: true,
          selectable: true,
          show: true,
        },
      ],
    };
  },
  methods: {
    handleFilters($event) {
      useFiltersStore().set(this.$route.name, $event);
      this.page = 1;
      if (this.filtersDebounce) {
        clearTimeout(this.filtersDebounce);
      }
      this.filtersDebounce = setTimeout(() => {
        this.get();
      }, 400);
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
    dayjs,
    entityHideOrPublish(id) {
      this.apollo()
        .admin.mutate({
          mutation: ENTITY_HIDE_OR_PUBLISH,
          variables: {
            id: id,
            table: "shops",
          },
        })
        .then(() => {
          this.get();
        })
        .catch(() => {
          console.log("error");
        });
    },
    remove(id) {
      if (confirm("Вы уверены?")) {
        this.apollo()
          .admin.mutate({
            mutation: REMOVE,
            variables: {
              id,
            },
          })
          .then(() => {
            this.get();
          })
          .catch(({ graphQLErrors }) => {});
      }
    },
    routeToUpdateShop(id) {
      this.$router.push({ name: "store", params: { id } });
    },
    normalizeBool(value) {
      return value === true || value === false ? value : undefined;
    },
    get() {
      this.rows.data = [];
      this.loading = true;
      const f = this.savedFilters;
      this.apollo()
        .admin.query({
          query: GET,
          variables: {
            first: f.first,
            page: this.page,
            search: f.search || undefined,
            active: this.normalizeBool(f.active),
            delivery_enabled: f.delivery_enabled === true ? true : undefined,
            pickup_enabled: f.pickup_enabled === true ? true : undefined,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.rows.data = data.shops_paginate.data;
          this.rows.paginatorInfo = data.shops_paginate.paginatorInfo;
        })
        .catch(() => {
          console.log("error");
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
  computed: {
    // Создание/удаление/скрытие магазинов гейтится на бэке onlyAdmin —
    // shop-scope ролям эти контролы не показываем (сам список им доступен,
    // но обрезан scope'ом accessible до их магазинов).
    isAdmin() {
      return useAuthStore().isGlobalUser;
    },
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Партнеры");
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
  components: {
    ImgComponent,
    FlagCheckboxComponent,
    TableComponent,
    ActionMenu,
    IconComponent,
    StoresTableHeader,
  },
};
</script>

<style lang="stylus">
.stores-page {

  &__list {
    display flex
    flex-direction column
    gap 8px
  }

  &__item-id {
    margin-right: 24px;
  }

  &__item {
    display flex
    justify-content space-between
    align-items center
    border 1px solid var(--gray-light)
    border-radius 16px
    padding 21px 24px

    div {
      display flex
      align-items center
    }

    span {
      font-weight 500
      font-size 0.813em
    }
  }

  &__item-time {
    display flex
    gap 3px

    span:last-child {
      color #8C8C8C
    }
  }

  &__table {
    .table__container {
      height: calc(100% - 54px)
    }
  }

  &__action {
    display flex
    position relative
    cursor pointer

    .icon {
      width 20px
      height 20px
    }

    &:hover {
      .action-menu {
        opacity 1
        visibility visible
      }
    }
  }
}
</style>
