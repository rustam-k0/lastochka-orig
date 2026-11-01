<template>
  <main class="page--main order-cancel-reason-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="order-cancel-reason-create-page__header">
      <h2>Создание причины отмены заказа</h2>
    </div>

    <div class="order-cancel-reason-create-page__content">
      <OrderCancelReasonForm @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import OrderCancelReasonForm from "./components/OrderCancelReasonForm.vue";

export default {
  name: "OrderCancelReasonCreatePage",
  components: {
    BreadcrumbsComponent,
    OrderCancelReasonForm,
  },
  data() {
    return {
      breadcrumbs: [
        { to: { name: "order-cancel-reasons" }, title: "Причины отмены заказа" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "order-cancel-reasons" });
    },
    handleCancel() {
      this.$router.push({ name: "order-cancel-reasons" });
    },
  },
};
</script>

<style lang="stylus">
.order-cancel-reason-create-page {
  &__header {
    margin-bottom 24px

    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }

  &__content {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
  }
}
</style>
