<template>
  <main class="page--main vacancy-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="vacancy-edit-page__header">
      <h2>Новая вакансия</h2>
    </div>

    <div class="vacancy-edit-page__content">
      <VacancyForm @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import VacancyForm from "./components/VacancyForm.vue";

export default {
  name: "VacancyCreatePage",
  components: {
    BreadcrumbsComponent,
    VacancyForm,
  },
  computed: {
    breadcrumbs() {
      return [{ to: { name: "vacancies" }, title: "Работа в компании" }, { title: "Создание" }];
    },
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "vacancies" });
    },
    handleCancel() {
      this.$router.push({ name: "vacancies" });
    },
  },
};
</script>

<style lang="stylus">
.vacancy-edit-page {
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
