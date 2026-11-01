<template>
  <main class="page--main alert-rules-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="alert-rules-page__header">
      <h2>{{ pageTitle }}</h2>
      <button class="btn btn--md" @click.prevent="openCreate">
        <IconComponent name="add-circle-line" />
        <span>Добавить правило</span>
      </button>
    </div>

    <div v-if="loading" class="alert-rules-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!items.length" class="alert-rules-page__empty">
      <h3>Правил сигналов пока нет</h3>
      <p>Добавьте первое правило, чтобы получать сигналы по отклонениям метрик</p>
      <button class="btn btn--md" @click.prevent="openCreate">
        <IconComponent name="add-circle-line" />
        <span>Добавить правило</span>
      </button>
    </div>

    <div v-else class="alert-rules-page__table-wrapper">
      <table class="alert-rules-page__table">
        <thead>
          <tr>
            <th>Название</th>
            <th>Метрика</th>
            <th>Условие</th>
            <th>Порог</th>
            <th>Магазин</th>
            <th>Активно</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ item.name }}</td>
            <td>{{ item.metric_key }}</td>
            <td>{{ comparisonLabel(item.comparison) }}</td>
            <td>{{ item.threshold_value }}</td>
            <td>{{ item.shop ? item.shop.title : "Все магазины" }}</td>
            <td>{{ item.is_active ? "Да" : "Нет" }}</td>
            <td class="alert-rules-page__row-actions">
              <button
                class="alert-rules-page__action-btn"
                title="Изменить"
                @click.prevent="openEdit(item)"
              >
                <IconComponent name="pencil-line" />
              </button>
              <button
                class="alert-rules-page__action-btn alert-rules-page__action-btn--delete"
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
import AlertRuleModal from "./modals/AlertRuleModal.vue";
import ALERT_RULES from "@/graphql/queries/admin_shop_alert_rules.graphql";
import ALERT_RULE_DELETE from "@/graphql/mutations/ShopAlertRuleDelete.graphql";
import SHOPS from "@/graphql/queries/shops_list.graphql";
import { useMenuStore } from "@/stores/menu.js";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "AlertRulesPage",
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
      return useMenuStore().titleFor(this.$route.name, "Правила сигналов");
    },
    breadcrumbs() {
      return [{ to: { name: "alert-rules" }, title: this.pageTitle }];
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
        .admin.query({ query: ALERT_RULES, fetchPolicy: "network-only" })
        .then(({ data }) => {
          this.items = data.admin_shop_alert_rules || [];
        })
        .catch((e) => console.error("Error fetching alert rules:", e))
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
    comparisonLabel(value) {
      return { lt: "<", lte: "≤", gt: ">", gte: "≥" }[value] || value;
    },
    openCreate() {
      useDefaultStore().modals.push({
        component: shallowRef(AlertRuleModal),
        options: { item: null, shops: this.shops, callback: this.get },
      });
    },
    openEdit(item) {
      useDefaultStore().modals.push({
        component: shallowRef(AlertRuleModal),
        options: { item, shops: this.shops, callback: this.get },
      });
    },
    confirmDelete(item) {
      if (!confirm("Удалить правило сигнала?")) return;
      this.apollo()
        .admin.mutate({ mutation: ALERT_RULE_DELETE, variables: { id: parseInt(item.id) } })
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
.alert-rules-page {
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
