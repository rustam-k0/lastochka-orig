<template>
  <main class="page--main attribute-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="attribute-edit-page__header">
      <h2>Редактирование атрибута</h2>
    </div>
    <div v-if="loadingData" class="attribute-edit-page__loading">Загрузка...</div>
    <div v-else class="attribute-edit-page__content">
      <AttributeForm
        :attribute="attribute"
        :isEdit="true"
        @success="onSuccess"
        @cancel="onCancel"
      />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import AttributeForm from "./components/AttributeForm.vue";
import ATTRIBUTE_ITEM from "@/graphql/queries/attributes_item.graphql";

export default {
  name: "AttributeEditPage",
  inject: ["apollo"],
  components: { BreadcrumbsComponent, AttributeForm },
  data() {
    return {
      attribute: null,
      loadingData: true,
      breadcrumbs: [
        { to: { name: "attributes" }, title: "Атрибуты" },
        { title: "Редактирование" },
      ],
    };
  },
  mounted() {
    this.loadAttribute();
  },
  methods: {
    loadAttribute() {
      const id = parseInt(this.$route.params.id);
      this.apollo()
        .admin.query({
          query: ATTRIBUTE_ITEM,
          variables: { id },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.attribute = data.attribute_item;
          if (!this.attribute) {
            this.$router.push({ name: "attributes" });
          }
        })
        .catch((error) => {
          console.error("AttributeItem load error:", error);
          this.$router.push({ name: "attributes" });
        })
        .finally(() => {
          this.loadingData = false;
        });
    },
    onSuccess() {
      this.$router.push({ name: "attributes" });
    },
    onCancel() {
      this.$router.push({ name: "attributes" });
    },
  },
};
</script>

<style lang="stylus">
.attribute-edit-page
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
