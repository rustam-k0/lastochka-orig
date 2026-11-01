<template>
  <main class="page--main kpi-targets-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="kpi-targets-page__header">
      <h2>{{ pageTitle }}</h2>
      <button class="btn btn--md" @click.prevent="openCreate">
        <IconComponent name="add-circle-line" />
        <span>Добавить показатель</span>
      </button>
    </div>

    <div v-if="loading" class="kpi-targets-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!items.length" class="kpi-targets-page__empty">
      <h3>Плановых показателей пока нет</h3>
      <p>Добавьте первый показатель, чтобы отслеживать выполнение KPI по метрикам</p>
      <button class="btn btn--md" @click.prevent="openCreate">
        <IconComponent name="add-circle-line" />
        <span>Добавить показатель</span>
      </button>
    </div>

    <div v-else class="kpi-targets-page__table-wrapper">
      <table class="kpi-targets-page__table">
        <thead>
          <tr>
            <th>Метрика</th>
            <th>Магазин</th>
            <th>Период</th>
            <th>Целевое значение</th>
            <th>Активно</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ item.metric_key }}</td>
            <td>{{ item.shop ? item.shop.title : "Вся сеть" }}</td>
            <td>{{ item.period_start }} — {{ item.period_end }}</td>
            <td>{{ item.target_value }}</td>
            <td>{{ item.is_active ? "Да" : "Нет" }}</td>
            <td class="kpi-targets-page__row-actions">
              <button
                class="kpi-targets-page__action-btn"
                title="Изменить"
                @click.prevent="openEdit(item)"
              >
                <IconComponent name="pencil-line" />
              </button>
              <button
                class="kpi-targets-page__action-btn kpi-targets-page__action-btn--delete"
                title="Удалить"
                @click.prevent="confirmDelete(item)"
              >
                <IconComponent name="delete-bin-line" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </main>
</template>

<script>
import { shallowRef } from "vue";
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import KpiTargetModal from "./modals/KpiTargetModal.vue";
import KPI_TARGETS from "@/graphql/queries/admin_kpi_targets.graphql";
import KPI_TARGET_DELETE from "@/graphql/mutations/KpiTargetDelete.graphql";
import SHOPS from "@/graphql/queries/shops_list.graphql";
import { useMenuStore } from "@/stores/menu.js";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "KpiTargetsPage",
  inject: ["apollo"],
  components: { BreadcrumbsComponent, IconComponent, LoadingIndicator },
  data() {
    return {
      items: [],
      shops: [],
      loading: true,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Плановые показатели");
    },
    breadcrumbs() {
      return [{ to: { name: "kpi-targets" }, title: this.pageTitle }];
    },
  },
  mounted() {
    this.get();
    this.loadShops();
  },
  methods: {
    get() {
      this.loading = true;
      this.apollo()
        .admin.query({ query: KPI_TARGETS, fetchPolicy: "network-only" })
        .then(({ data }) => {
          this.items = data.admin_kpi_targets || [];
        })
        .catch((e) => console.error("Error fetching kpi targets:", e))
        .finally(() => {
          this.loading = false;
        });
    },
    loadShops() {
      // Тот же документ, что используется для выбора магазина в других формах
      // проекта (StaffAssemblyZones, aggregator/quarantine, OperatingCostsPage) —
      // полный список { id, title } без пагинации, идеально подходит для SelectComponent.
      this.apollo()
        .admin.query({ query: SHOPS, fetchPolicy: "cache-first" })
        .then(({ data }) => {
          this.shops = data.shops || [];
        })
        .catch(() => {
          this.shops = [];
        });
    },
    openCreate() {
      useDefaultStore().modals.push({
        component: shallowRef(KpiTargetModal),
        options: { item: null, shops: this.shops, callback: this.get },
      });
    },
    openEdit(item) {
      useDefaultStore().modals.push({
        component: shallowRef(KpiTargetModal),
        options: { item, shops: this.shops, callback: this.get },
      });
    },
    confirmDelete(item) {
      if (!confirm("Удалить плановый показатель?")) return;
      this.apollo()
        .admin.mutate({ mutation: KPI_TARGET_DELETE, variables: { id: parseInt(item.id) } })
        .then(() => this.get())
        .catch((e) => {
          console.error(e);
          alert("Ошибка удаления");
        });
    },
  },
};
</script>

<style lang="stylus">
.kpi-targets-page {
  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px

    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }

  &__loading {
    display flex
    justify-content center
    padding 48px
  }

  &__empty {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center

    h3 {
      font-size 1.25rem
      color var(--dark)
      margin-bottom 8px
    }

    p {
      font-size 0.875rem
      color var(--dark-light)
      margin-bottom 24px
    }
  }

  &__table-wrapper {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    overflow hidden
  }

  &__table {
    width 100%
    border-collapse collapse

    th, td {
      padding 16px
      text-align left
      font-size 0.875rem
      border-bottom 1px solid var(--gray-dark)
    }

    th {
      font-weight 600
      color var(--dark-light)
      background var(--gray-3)
    }

    td {
      color var(--dark)
    }

    tbody tr {
      transition background 0.2s

      &:hover {
        background var(--gray-3)
      }

      &:last-child td {
        border-bottom none
      }
    }
  }

  &__row-actions {
    display flex
    gap 8px
  }

  &__action-btn {
    display flex
    align-items center
    justify-content center
    width 36px
    height 36px
    border-radius 8px
    border 1px solid var(--gray-dark)
    background var(--white)
    cursor pointer
    transition all 0.2s

    .icon {
      width 18px
      height 18px

      svg path {
        fill var(--dark)
      }
    }

    &:hover {
      border-color var(--pink)

      .icon svg path {
        fill var(--pink)
      }
    }

    &--delete:hover {
      border-color var(--red)

      .icon svg path {
        fill var(--red)
      }
    }
  }
}
</style>
