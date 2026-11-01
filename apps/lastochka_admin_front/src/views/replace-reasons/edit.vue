<template>
  <main class="page--main replace-reason-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="replace-reason-edit-page__header">
      <h2>Редактирование причины замены</h2>
    </div>

    <div class="replace-reason-edit-page__content">
      <ReplaceReasonForm :id="reasonId" @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import ReplaceReasonForm from "./components/ReplaceReasonForm.vue";

export default {
  name: "ReplaceReasonEditPage",
  components: {
    BreadcrumbsComponent,
    ReplaceReasonForm,
  },
  computed: {
    reasonId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [
        { to: { name: "replace-reasons" }, title: "Причины замены" },
        { title: "Редактирование" },
      ];
    },
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "replace-reasons" });
    },
    handleCancel() {
      this.$router.push({ name: "replace-reasons" });
    },
  },
};
</script>

<style lang="stylus">
.replace-reason-edit-page {
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
