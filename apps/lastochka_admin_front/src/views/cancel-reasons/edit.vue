<template>
  <main class="page--main cancel-reason-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="cancel-reason-edit-page__header">
      <h2>Редактирование причины отмены</h2>
    </div>

    <div class="cancel-reason-edit-page__content">
      <CancelReasonForm :id="reasonId" @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import CancelReasonForm from "./components/CancelReasonForm.vue";

export default {
  name: "CancelReasonEditPage",
  components: {
    BreadcrumbsComponent,
    CancelReasonForm,
  },
  computed: {
    reasonId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [
        { to: { name: "cancel-reasons" }, title: "Причины отмены" },
        { title: "Редактирование" },
      ];
    },
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "cancel-reasons" });
    },
    handleCancel() {
      this.$router.push({ name: "cancel-reasons" });
    },
  },
};
</script>

<style lang="stylus">
.cancel-reason-edit-page {
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
