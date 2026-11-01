<template>
  <main class="page--main explain-reason-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="explain-reason-create-page__header">
      <h2>Создание причины уточнения</h2>
    </div>

    <div class="explain-reason-create-page__content">
      <ExplainReasonForm @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import ExplainReasonForm from "./components/ExplainReasonForm.vue";

export default {
  name: "ExplainReasonCreatePage",
  components: {
    BreadcrumbsComponent,
    ExplainReasonForm,
  },
  data() {
    return {
      breadcrumbs: [
        { to: { name: "explain-reasons" }, title: "Причины уточнения" },
        { title: "Создание" },
      ],
    };
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
.explain-reason-create-page {
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
