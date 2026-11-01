<template>
  <main class="page--main vacancy-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="vacancy-edit-page__header">
      <h2>Карточка вакансии</h2>
    </div>

    <div class="vacancy-edit-page__content">
      <VacancyForm :id="vacancyId" @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import VacancyForm from "./components/VacancyForm.vue";

export default {
  name: "VacancyEditPage",
  components: {
    BreadcrumbsComponent,
    VacancyForm,
  },
  computed: {
    vacancyId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [{ to: { name: "vacancies" }, title: "Работа в компании" }, { title: "Редактирование" }];
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
