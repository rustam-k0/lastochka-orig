<template>
  <main class="page--main faq-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="faq-edit-page__header">
      <h2>Редактирование вопроса</h2>
    </div>

    <div class="faq-edit-page__content">
      <FaqForm :id="faqId" @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import FaqForm from "./components/FaqForm.vue";

export default {
  name: "FaqEditPage",
  components: {
    BreadcrumbsComponent,
    FaqForm,
  },
  computed: {
    faqId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [{ to: { name: "faq" }, title: "Вопросы и ответы" }, { title: "Редактирование" }];
    },
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
.faq-edit-page {
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
