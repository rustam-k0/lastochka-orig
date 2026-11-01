<template>
  <main class="page--main family-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="family-edit-page__header">
      <h2>Редактирование семейства атрибутов</h2>
    </div>
    <div v-if="loadingData" class="family-edit-page__loading">Загрузка...</div>
    <div v-else class="family-edit-page__content">
      <AttributeFamilyForm
        :family="family"
        :loading="saving"
        :isEdit="true"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import AttributeFamilyForm from "./components/AttributeFamilyForm.vue";
import ATTRIBUTE_FAMILY from "@/graphql/queries/attribute_family.graphql";
import ATTRIBUTE_FAMILY_UPDATE from "@/graphql/mutations/AttributeFamilyUpdate.graphql";

export default {
  name: "AttributeFamilyEditPage",
  inject: ["apollo"],
  components: { BreadcrumbsComponent, AttributeFamilyForm },
  data() {
    return {
      family: null,
      loadingData: true,
      saving: false,
      breadcrumbs: [
        { to: { name: "attribute-families" }, title: "Семейства атрибутов" },
        { title: "Редактирование" },
      ],
    };
  },
  mounted() {
    this.loadFamily();
  },
  methods: {
    loadFamily() {
      const id = parseInt(this.$route.params.id);
      this.apollo()
        .admin.query({
          query: ATTRIBUTE_FAMILY,
          variables: { id },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.family = data.attribute_family;
          if (!this.family) {
            alert("Семейство атрибутов не найдено");
            this.$router.push({ name: "attribute-families" });
          }
        })
        .catch((error) => {
          console.error("Error loading attribute family:", error);
          alert("Ошибка загрузки семейства атрибутов");
          this.$router.push({ name: "attribute-families" });
        })
        .finally(() => {
          this.loadingData = false;
        });
    },
    handleSubmit(formData) {
      this.saving = true;
      const id = parseInt(this.$route.params.id);
      this.apollo()
        .admin.mutate({
          mutation: ATTRIBUTE_FAMILY_UPDATE,
          variables: { id, ...formData },
        })
        .then(() => {
          this.$router.push({ name: "attribute-families" });
        })
        .catch((error) => {
          console.error("Error updating attribute family:", error);
          alert(error.message || "Ошибка обновления семейства атрибутов");
        })
        .finally(() => {
          this.saving = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "attribute-families" });
    },
  },
};
</script>

<style lang="stylus">
.family-edit-page
  &__header
    margin-bottom 24px
    h2
      font-size 1.5rem
      font-weight 600

  &__loading
    text-align center
    padding 48px
    color #999

  &__content
    background #fff
    border-radius 12px
    border 1px solid #eee
    padding 32px
</style>
