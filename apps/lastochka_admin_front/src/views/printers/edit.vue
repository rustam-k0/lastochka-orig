<template>
  <main class="page--main printers-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="printers-edit-page__header">
      <h2>Редактирование принтера</h2>
    </div>

    <div class="printers-edit-page__content">
      <PrinterForm :id="printerId" @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import PrinterForm from "./components/PrinterForm.vue";

export default {
  name: "PrinterEditPage",
  components: {
    BreadcrumbsComponent,
    PrinterForm,
  },
  computed: {
    printerId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [{ to: { name: "printers" }, title: "Принтеры печати" }, { title: "Редактирование" }];
    },
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "printers" });
    },
    handleCancel() {
      this.$router.push({ name: "printers" });
    },
  },
};
</script>

<style lang="stylus">
.printers-edit-page {
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
