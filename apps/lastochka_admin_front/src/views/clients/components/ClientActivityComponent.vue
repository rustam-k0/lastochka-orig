<template>
  <section class="client-activity" v-if="info">
    <div class="client-activity__wrapper">
      <div class="client-activity__header">
        <div class="client-activity__name">
          <div class="status status--blue client-activity__icon">
            <IconComponent name="shopping-basket-2-line" />
          </div>
          <span class="client-activity__title">Заказы</span>
        </div>
      </div>
      <div>
        <span class="client-activity__quantity-order">{{ info.orders_count }}</span>
        <span class="client-activity__amount-order"> ({{ formatPrice(info.orders_amount) }})</span>
      </div>
    </div>
    <div class="client-activity__wrapper" v-if="moduleStore.isModuleEnabled('bonuses')">
      <div class="client-activity__header">
        <div class="client-activity__name">
          <div class="status status--pink client-activity__icon">
            <IconComponent name="copper-diamond-line" />
          </div>
          <span class="client-activity__title">Баллы</span>
        </div>
        <button @click="openBonusModal" class="btn btn--sm btn--white">
          <IconComponent name="pencil-line" />
        </button>
      </div>
      <div class="client-activity__value">
        <span>{{ availableBonus }}</span>
        <div class="client-activity__date" v-if="reservedBonus > 0">зарезервировано: {{ reservedBonus }}</div>
      </div>
    </div>
    <!--    <div class="client-activity__wrapper">-->
    <!--      <div class="client-activity__header">-->
    <!--        <div class="client-activity__name">-->
    <!--          <div class="status status&#45;&#45;green client-activity__icon">-->
    <!--            <IconComponent name="percent-line" />-->
    <!--          </div>-->
    <!--          <span class="client-activity__title">Кешбэк</span>-->
    <!--        </div>-->
    <!--        <button @click="openSaleModal(user.sale)" class="btn btn&#45;&#45;sm btn&#45;&#45;white">-->
    <!--          <IconComponent name="pencil-line" />-->
    <!--        </button>-->
    <!--      </div>-->
    <!--      <div class="client-activity__value">-->
    <!--        <span>{{ user.sale }}</span>-->
    <!--      </div>-->
    <!--    </div>-->
    <!--    <div class="client-activity__wrapper">-->
    <!--      <div class="client-activity__header">-->
    <!--        <div class="client-activity__name">-->
    <!--          <div class="status status&#45;&#45;yellow client-activity__icon">-->
    <!--            <IconComponent name="shopping-basket-2-line" />-->
    <!--          </div>-->
    <!--          <span class="client-activity__title">Последняя покупка</span>-->
    <!--        </div>-->
    <!--      </div>-->
    <!--      <div class="client-activity__value">-->
    <!--        <span>{{ user.lastPurchase.amount }} ₽</span>-->
    <!--        <div class="client-activity__date">-->
    <!--          {{ user.lastPurchase.date }}-->
    <!--        </div>-->
    <!--      </div>-->
    <!--    </div>-->
    <div class="client-activity__wrapper">
      <div class="client-activity__header">
        <div class="client-activity__name">
          <div class="status status--purple client-activity__icon">
            <IconComponent name="wallet-3-line" />
          </div>
          <span class="client-activity__title">сред. сумма покупок</span>
        </div>
      </div>
      <div>
        <div class="client-activity__value">
          <span>{{ formatPrice(info.orders_avg) }}</span>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { useDefaultStore } from "@/stores/default.js";
import { shallowRef } from "vue";
import SaleModal from "@/components/modals/components/SaleModal.vue";
import ClientBonusModal from "@/views/clients/modals/ClientBonusModal.vue";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { formatPrice } from "../../../utils.js";

export default {
  name: "ClientActivityComponent",
  components: { IconComponent },
  props: {
    info: Object,
  },
  emits: ["operation-success"],
  data() {
    return {
      user: {
        quantityOrder: "46",
        amountOrder: "8 186,76",
        points: "100",
        spentPoints: "300",
        sale: "3%",
        lastPurchase: {
          amount: "885,83",
          date: "11.09.2022",
        },
        averageAmount: "1 186,76",
      },
    };
  },
  computed: {
    moduleStore() {
      return useModuleConfigStore();
    },
    availableBonus() {
      return Math.max(0, (this.info?.bonus_balance || 0) - (this.info?.bonus_reserved || 0));
    },
    reservedBonus() {
      return this.info?.bonus_reserved || 0;
    },
  },
  methods: {
    formatPrice,
    openBonusModal() {
      useDefaultStore().modals.push({
        component: shallowRef(ClientBonusModal),
        options: {
          clientId: this.info.id,
          balance: this.info.bonus_balance || 0,
          reserved: this.info.bonus_reserved || 0,
          callback: () => {
            this.$emit("operation-success");
          },
        },
      });
    },
    openSaleModal(value) {
      useDefaultStore().modals.push({
        component: shallowRef(SaleModal),
        options: {
          value,
        },
      });
    },
  },
};
</script>

<style lang="stylus">
.client-activity {
  width 100%
  display flex
  gap 16px
  color var(--dark)

  & .icon {
    width 20px
    height 20px
  }

  &__wrapper {
    padding: 24px
    background: var(--white)
    border-radius: 8px;
    width 100%
    display flex
    flex-direction column
    gap 16px
  }

  &__header {
    display flex
    justify-content space-between
  }

  &__name {
    display flex
    gap 10px
    align-items center
  }

  &__icon {
    width 36px
    height 36px
  }

  &__title {
    font-weight: 500;
    font-size: 0.813rem
    line-height: 16px;
    text-transform uppercase
  }

  &__value {
    display flex
    gap 16px
    align-items center

    & span {
      font-family var(--font-bold)
      font-weight: 600;
      font-size: 1.75rem
      line-height: 39px;
    }
  }

  &__quantity-order {
    font-family var(--font-bold)
    font-weight: 600;
    font-size: 1.75rem
    line-height: 39px;
  }

  &__amount-order {
    font-family var(--font-bold)
    font-weight: 500;
    font-size: 1.25rem
    line-height: 28px;
  }

  &__date {
    background: var(--blue);
    border-radius: 16px;
    height 24px
    padding: 4px 12px
    font-weight: 500;
    font-size: 0.75rem
    line-height: 16px;
    text-align: right;
  }
}
</style>
