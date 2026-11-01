<template>
  <main class="announcements-page">
    <div class="announcements-page__header">
      <h2>{{ pageTitle }}</h2>
      <router-link :to="{ name: 'announcement-create' }" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Добавить уведомление</span>
      </router-link>
    </div>
    <TableComponent
      class="announcements-page__table"
      :class="{ 'announcements-page__table--filters-none': !showFilters }"
      v-if="rows"
      :ordering="ordering"
      :columns="(columns || []).filter((c) => c.show || c.system)"
      :rows="rows.data || []"
      :loading="loading"
      @update:selected="handleSelected"
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
          placeholder="Поиск по уведомлениям"
        />
      </template>
      <template #col="{ col, row }">
        <div
          v-if="col.field === 'status'"
          :class="{
            'rows__indicator--green': row.is_active,
            'rows__indicator--gray': !row.is_active,
          }"
        >
          <span></span>
        </div>
        <router-link
          v-if="col.field === 'title'"
          :to="{ name: 'announcement-edit', params: { id: row.id } }"
        >
          {{ row.title }}
        </router-link>
        <span v-if="col.field === 'short_description'">
          {{ row.short_description }}
        </span>
        <span v-if="col.field === 'promocode'">
          {{ row.promocode ? row.promocode.title : "-" }}
        </span>
        <span v-if="col.field === 'created_at'">
          {{ formatDate(row.created_at) }}
        </span>
        <div v-if="col.field === 'active'" style="padding-left: 30px">
          <FlagCheckboxComponent
            :model-value="row.is_active"
            @update:model-value="toggleActive($event, row.id)"
          />
        </div>
        <div v-if="col.field === 'actions'" class="table-actions">
          <button
            v-if="row.is_active"
            @click="sendNotification(row)"
            class="btn btn--sm btn--notification"
            :disabled="notificationLoading === row.id"
            :title="
              notificationLoading === row.id
                ? 'Отправка...'
                : 'Отправить уведомление'
            "
          >
            <IconComponent name="notification-2-line" />
          </button>
          <button
            @click="deleteAnnouncement(row.id)"
            class="btn btn--sm btn--danger"
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
import TableHeader from "@/views/announcements/components/TableHeader.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import { useFiltersStore } from "@/stores/pageFilters.js";
import ANNOUNCEMENTS from "@/graphql/queries/announcements_paginate.graphql";
import ANNOUNCEMENT_UPDATE from "@/graphql/mutations/AnnouncementUpdate.graphql";
import ANNOUNCEMENT_DELETE from "@/graphql/mutations/AnnouncementDelete.graphql";
import { useNotifications } from "@/composables/useNotifications.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "AnnouncementsPage",
  inject: ["apollo"],

  async mounted() {
    this.notifications = useNotifications();
    await this.get();
  },

  data() {
    return {
      showFilters: false,
      loading: false,
      searchDebounce: null,
      page: 1,
      first: 25,
      notificationLoading: null,
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
          title: "",
          field: "status",
          show: true,
          width: "50px",
        },
        {
          title: "Заголовок",
          field: "title",
          selectable: true,
          show: true,
        },
        {
          title: "Краткое описание",
          field: "short_description",
          selectable: true,
          show: true,
        },
        {
          title: "Промокод",
          field: "promocode",
          selectable: true,
          show: true,
        },
        {
          title: "Дата создания",
          field: "created_at",
          selectable: true,
          show: true,
        },
        {
          title: "Активность",
          field: "active",
          selectable: true,
          show: true,
        },
        {
          title: "Действия",
          field: "actions",
          show: true,
          width: "120px",
        },
      ],
    };
  },

  methods: {
    toggleShowFilters($event) {
      this.showFilters = $event;
    },
    handleSelected($event) {
      this.selected = [...$event];
    },
    handleFilters($event) {
      if (this.searchDebounce) {
        clearTimeout(this.searchDebounce);
      }
      if (!this.loading) {
        this.searchDebounce = setTimeout(() => {
          useFiltersStore().set(this.$route.name, $event);
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
    },

    async toggleActive(value, id) {
      try {
        await this.apollo().admin.mutate({
          mutation: ANNOUNCEMENT_UPDATE,
          variables: {
            id: id,
            is_active: value,
          },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при изменении статуса:", error);
        alert("Ошибка при изменении статуса уведомления");
      }
    },

    async deleteAnnouncement(id) {
      if (!confirm("Вы уверены, что хотите удалить это уведомление?")) {
        return;
      }

      try {
        await this.apollo().admin.mutate({
          mutation: ANNOUNCEMENT_DELETE,
          variables: { id: id },
        });
        await this.get();
      } catch (error) {
        console.error("Ошибка при удалении:", error);
        alert("Ошибка при удалении уведомления");
      }
    },

    async sendNotification(announcement) {
      if (
        !confirm(
          `Отправить push-уведомление "${announcement.title}" всем пользователям?`
        )
      ) {
        return;
      }

      try {
        this.notificationLoading = announcement.id;
        await this.notifications.sendAnnouncementNotification(
          this.apollo(),
          announcement.id,
          { target: "ALL_USERS" }
        );
      } catch (error) {
        console.error("Ошибка при отправке уведомления:", error);
      } finally {
        this.notificationLoading = null;
      }
    },

    async get() {
      if (!this.loading) {
        this.loading = true;
        const variables = {
          first: this.savedFilters.first,
          page: this.page,
          search: this.savedFilters.search,
          is_active: this.savedFilters.is_active,
          promocode_id: this.savedFilters.promocode_id,
        };

        this.rows.data = [];
        try {
          const { data } = await this.apollo().admin.query({
            query: ANNOUNCEMENTS,
            variables,
          });

          if (data.announcements) {
            this.rows.data = data.announcements.data;
            this.rows.paginatorInfo = data.announcements.paginatorInfo;
          }
        } catch (error) {
          console.error("Ошибка при загрузке уведомлений:", error);
        } finally {
          this.loading = false;
        }
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

  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Уведомления об акциях");
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...(useFiltersStore().get(this.$route.name) || {
            search: null,
            is_active: null,
            promocode_id: null,
          }),
        },
      };
    },
  },

  components: {
    TableComponent,
    TableHeader,
    FlagCheckboxComponent,
    IconComponent,
  },
};
</script>

<style lang="stylus">
.announcements-page {
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

    button {
      height 48px
    }
  }
}

.table-actions {
  display flex
  gap 8px

  .btn--notification {
    background linear-gradient(135deg, #667eea 0%, #764ba2 100%)
    color white
    border none
    display inline-flex
    align-items center
    gap 4px
    padding 6px 12px
    border-radius 4px
    font-size 12px
    transition all 0.2s ease

    &:hover:not(:disabled) {
      transform translateY(-1px)
      box-shadow 0 4px 12px rgba(102, 126, 234, 0.4)
    }

    &:disabled {
      opacity 0.6
      cursor not-allowed
      transform none
    }

    .icon {
      width 14px
      height 14px
    }
  }
}
</style>
