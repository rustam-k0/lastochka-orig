<template>
  <div class="orders-page__cards">
    <OrderCard v-for="item in 5" :error="item === 2" :key="item" />
  </div>
</template>

<script>
import OrderCard from "@/components/OrderCard.vue";
export default {
  name: "OrdersPageCards",

  components: {
    OrderCard,
  },
};
</script>

<style lang="stylus">
.orders-page {
  &__cards {
    display grid
    grid-template-columns repeat(5, 1fr)
    grid-gap 10px
  }
}
</style>
