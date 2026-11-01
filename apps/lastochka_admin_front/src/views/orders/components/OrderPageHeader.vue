<template>
  <div class="page__header">
    <router-link :to="{ name: 'orders' }" class="order-page__order-number">
      <button class="btn btn--sm btn--white">
        <IconComponent name="arrow-left-line" />
      </button>
      <span class="order-page__title">Заказ {{ number || id }}</span>
    </router-link>
    <div class="order-page__actions">
      <!--      <button class="btn btn&#45;&#45;sm btn&#45;&#45;white">-->
      <!--        <IconComponent name="stackshare-line" />-->
      <!--        <span>Разделить</span>-->
      <!--      </button>-->
      <!--      <button class="btn btn&#45;&#45;sm">-->
      <!--        <IconComponent name="checkbox-circle-line" />-->
      <!--        <span>Сохранить</span>-->
      <!--      </button>-->
      <button
        v-if="statusId < 5 && canCancel"
        class="btn btn--red btn--sm"
        :class="{ 'btn--loading': canceling }"
        @click="cancelOrder"
      >
        {{ this.canceling ? "Отменяется" : "Отменить заказ" }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { useAuthStore } from "@/stores/auth.js";
export default {
  name: "OrderPageHeader",
  emits: ["cancel-order"],

  mounted() {},

  props: {
    id: {
      type: Number,
    },
    number: {
      type: String,
      default: null,
    },
    statusId: {
      type: Number,
    },
    canceling: {
      type: Boolean,
    },
  },

  methods: {
    cancelOrder() {
      this.$emit("cancel-order");
    },
  },
  computed: {
    canCancel() {
      return useAuthStore().canOrderAction("orders.cancel");
    },
  },

  components: {
    IconComponent,
  },
};
</script>
