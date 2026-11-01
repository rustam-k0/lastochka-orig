<template>
  <main class="page--main operating-costs-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="operating-costs-page__header">
      <h2>{{ pageTitle }}</h2>
      <button class="btn btn--md" @click.prevent="openCreate">
        <IconComponent name="add-circle-line" />
        <span>Добавить правило</span>
      </button>
    </div>

    <div v-if="loading" class="operating-costs-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!items.length" class="operating-costs-page__empty">
      <h3>Правил расходов пока нет</h3>
      <p>Добавьте первое правило, чтобы учитывать операционные расходы в аналитике</p>
      <button class="btn btn--md" @click.prevent="openCreate">
        <IconComponent name="add-circle-line" />
        <span>Добавить правило</span>
      </button>
    </div>

    <div v-else class="operating-costs-page__table-wrapper">
      <table class="operating-costs-page__table">
        <thead>
          <tr>
            <th>Магазин</th>
            <th>Категория</th>
            <th>Тип правила</th>
            <th>Значение</th>
            <th>Период</th>
            <th>Активно</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ item.shop ? item.shop.title : "Вся сеть" }}</td>
            <td>{{ categoryLabel(item.category) }}</td>
            <td>{{ ruleTypeLabel(item.rule_type) }}</td>
            <td>{{ item.rule_type === "pct_of_revenue" ? item.value + " %" : item.value + " ₽" }}</td>
            <td>{{ item.period_start }} — {{ item.period_end || "бессрочно" }}</td>
            <td>{{ item.is_active ? "Да" : "Нет" }}</td>
            <td class="operating-costs-page__row-actions">
              <button
                class="operating-costs-page__action-btn"
                title="Изменить"
                @click.prevent="openEdit(item)"
              >
                <IconComponent name="pencil-line" />
              </button>
              <button
                class="operating-costs-page__action-btn operating-costs-page__action-btn--delete"
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
import OperatingCostModal from "./modals/OperatingCostModal.vue";
import COSTS from "@/graphql/queries/admin_operating_costs.graphql";
import COST_DELETE from "@/graphql/mutations/OperatingCostDelete.graphql";
import SHOPS from "@/graphql/queries/shops_list.graphql";
import { useMenuStore } from "@/stores/menu.js";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "OperatingCostsPage",
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
      return useMenuStore().titleFor(this.$route.name, "Операционные расходы");
    },
    breadcrumbs() {
      return [{ to: { name: "operating-costs" }, title: this.pageTitle }];
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
        .admin.query({ query: COSTS, fetchPolicy: "network-only" })
        .then(({ data }) => {
          this.items = data.admin_operating_costs || [];
        })
        .catch((e) => console.error("Error fetching operating costs:", e))
        .finally(() => {
          this.loading = false;
        });
    },
    loadShops() {
      // Тот же документ, что используется для выбора магазина в других формах
      // проекта (StaffAssemblyZones, aggregator/quarantine) — полный список
      // { id, title } без пагинации, идеально подходит для SelectComponent.
      // Страница /admin/stores использует shops_paginate (таблица с пагинацией,
      // логотипами и т.п.) — избыточно для простого выпадающего списка.
      this.apollo()
        .admin.query({ query: SHOPS, fetchPolicy: "cache-first" })
        .then(({ data }) => {
          this.shops = data.shops || [];
        })
        .catch(() => {
          this.shops = [];
        });
    },
    categoryLabel(value) {
      return (
        { rent: "Аренда", payroll: "ФОТ", marketing: "Маркетинг/реклама", other: "Прочее" }[value] || value
      );
    },
    ruleTypeLabel(value) {
      return { fixed_amount: "Фиксированная сумма", pct_of_revenue: "% от оборота" }[value] || value;
    },
    openCreate() {
      useDefaultStore().modals.push({
        component: shallowRef(OperatingCostModal),
        options: { item: null, shops: this.shops, callback: this.get },
      });
    },
    openEdit(item) {
      useDefaultStore().modals.push({
        component: shallowRef(OperatingCostModal),
        options: { item, shops: this.shops, callback: this.get },
      });
    },
    confirmDelete(item) {
      if (!confirm("Удалить правило расхода?")) return;
      this.apollo()
        .admin.mutate({ mutation: COST_DELETE, variables: { id: parseInt(item.id) } })
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
.operating-costs-page {
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
