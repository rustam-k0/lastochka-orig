<template>
  <main class="page--main faq-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="faq-create-page__header">
      <h2>Создание вопроса</h2>
    </div>

    <div class="faq-create-page__content">
      <FaqForm @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import FaqForm from "./components/FaqForm.vue";

export default {
  name: "FaqCreatePage",
  components: {
    BreadcrumbsComponent,
    FaqForm,
  },
  data() {
    return {
      breadcrumbs: [{ to: { name: "faq" }, title: "Вопросы и ответы" }, { title: "Создание" }],
    };
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "faq" });
    },
    handleCancel() {
      this.$router.push({ name: "faq" });
    },
  },
};
</script>

<style lang="stylus">
.faq-create-page {
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
