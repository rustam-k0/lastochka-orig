<template>
  <div class="order-page-history" v-if="items.length">
    <div class="order-page-history__header">История изменений</div>
    <ul class="order-page-history__content">
      <li v-for="item in items" :key="item.id">
        <div class="order-page-history__row">
          <IconComponent :name="actionIcon(item.action)" />
          <div class="order-page-history__body">
            <div class="order-page-history__top">
              <span class="order-page-history__action">{{ actionLabel(item.action) }}</span>
              <span class="order-page-history__date">{{ dateTimeFormat(item.created_at) }}</span>
            </div>
            <div v-if="subOrderBadge(item.order_id)" class="order-page-history__suborder-badge">
              {{ subOrderBadge(item.order_id) }}
            </div>
            <div class="order-page-history__actor">{{ item.actor_name || "—" }}</div>
            <div v-if="showValues(item)" class="order-page-history__values">
              <span v-if="item.old_value">{{ item.old_value }}</span>
              <IconComponent v-if="item.old_value && item.new_value" name="arrow-right-line" />
              <span v-if="item.new_value">{{ item.new_value }}</span>
            </div>
            <div v-if="item.comment" class="order-page-history__comment">{{ item.comment }}</div>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import ORDER_HISTORY from "@/graphql/pages/Order/order_history.graphql";
import dayjs from "dayjs";

const ACTION_LABELS = {
  created: "Заказ создан",
  status_changed: "Изменение статуса",
  collector_assigned: "Назначен сборщик",
  courier_assigned: "Назначен курьер",
  canceled: "Заказ отменён",
  item_replaced: "Товар заменён",
  item_confirmed: "Товар подтверждён",
  assembly_paused: "Сборка приостановлена",
  payment_fixed: "Оплата",
  delivery_completed: "Доставка завершена",
};

const ACTION_ICONS = {
  created: "file-add-line",
  status_changed: "refresh-line",
  collector_assigned: "archive-line",
  courier_assigned: "car-line",
  canceled: "close-circle-line",
  item_replaced: "exchange-line",
  item_confirmed: "check-line",
  assembly_paused: "pause-circle-line",
  payment_fixed: "bank-card-line",
  delivery_completed: "checkbox-circle-line",
};

export default {
  name: "OrderPageHistory",

  inject: ["apollo"],

  props: {
    orderId: {
      type: Number,
      default: null,
    },
    subOrders: {
      type: Array,
      default: () => [],
    },
  },

  data() {
    return {
      items: [],
    };
  },

  watch: {
    orderId: {
      immediate: true,
      handler() {
        this.fetch();
      },
    },
  },

  methods: {
    fetch() {
      if (!this.orderId) {
        this.items = [];
        return;
      }
      this.apollo()
        .purchase.query({
          query: ORDER_HISTORY,
          variables: { order_id: this.orderId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.items = (data && data.admin_order_history) || [];
        })
        .catch(() => {
          this.items = [];
        });
    },
    actionLabel(action) {
      return ACTION_LABELS[action] || action;
    },
    showValues(item) {
      // Самоназначение (сборщик/курьер сам взял заказ): actor_name и
      // new_value — один и тот же человек, вторая строка дублировала
      // первую (MID-203). При назначении админом или переназначении
      // (есть old_value) блок значений показывается как раньше.
      if (!item.old_value && item.new_value && item.new_value === item.actor_name) {
        return false;
      }
      return Boolean(item.old_value || item.new_value);
    },
    actionIcon(action) {
      return ACTION_ICONS[action] || "information-line";
    },
    dateTimeFormat(date) {
      return dayjs(date).format("DD.MM.YYYY HH:mm");
    },
    subOrderBadge(entryOrderId) {
      if (entryOrderId === this.orderId) return null;
      const sub = (this.subOrders || []).find((s) => s.id === entryOrderId);
      if (!sub) return `Подзаказ #${entryOrderId}`;
      const zone = sub.assemblyZone?.title || "без зоны";
      return `Подзаказ ${sub.label} · ${zone}`;
    },
  },

  components: {
    IconComponent,
  },
};
</script>

<style lang="stylus">
.order-page-history {
  display flex
  flex-direction column
  gap 12px
  padding 16px
  background var(--white)
  border-radius 16px

  &__header {
    font-weight 600
    font-size 0.875rem
  }

  &__content {
    display flex
    flex-direction column

    li {
      padding 12px 0
      border-top 1px solid var(--white-2)

      &:first-child {
        border-top none
        padding-top 0
      }
    }
  }

  &__row {
    display flex
    gap 12px
    align-items flex-start

    > .icon {
      width 24px
      height 24px
      flex-shrink 0
    }
  }

  &__body {
    display flex
    flex-direction column
    gap 4px
    flex 1
  }

  &__top {
    display flex
    justify-content space-between
    align-items center
  }

  &__action {
    font-weight 500
    font-size 0.875rem
  }

  &__date {
    font-size 0.75rem
    color var(--dark)
  }

  &__actor {
    font-size 0.813rem
    color var(--dark)
  }

  &__suborder-badge {
    display inline-block
    width fit-content
    padding 2px 8px
    border-radius 8px
    background var(--white-2)
    font-size 0.75rem
    color var(--dark)
  }

  &__values {
    display flex
    align-items center
    gap 6px
    font-size 0.813rem

    .icon {
      width 14px
      height 14px
      flex-shrink 0
    }
  }

  &__comment {
    font-size 0.813rem
    color var(--dark)
    font-style italic
  }
}
</style>
