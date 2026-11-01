<template>
  <main class="page--main explain-reason-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="explain-reason-edit-page__header">
      <h2>Редактирование причины уточнения</h2>
    </div>

    <div class="explain-reason-edit-page__content">
      <ExplainReasonForm :id="reasonId" @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import ExplainReasonForm from "./components/ExplainReasonForm.vue";

export default {
  name: "ExplainReasonEditPage",
  components: {
    BreadcrumbsComponent,
    ExplainReasonForm,
  },
  computed: {
    reasonId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [
        { to: { name: "explain-reasons" }, title: "Причины уточнения" },
        { title: "Редактирование" },
      ];
    },
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "explain-reasons" });
    },
    handleCancel() {
      this.$router.push({ name: "explain-reasons" });
    },
  },
};
</script>

<style lang="stylus">
.explain-reason-edit-page {
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
