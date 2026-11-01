<template>
  <main class="page--main merchant-promocode-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-promocode-create-page__header">
      <h2>Создание промокода</h2>
    </div>
    
    <div class="merchant-promocode-create-page__content">
      <MerchantPromocodeForm
        :loading="loading"
        :isEdit="false"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import MerchantPromocodeForm from "./components/MerchantPromocodeForm.vue";
import MERCHANT_PROMOCODE_CREATE from "@/graphql/mutations/MerchantPromocodeCreate.graphql";

export default {
  name: "MerchantPromocodeCreatePage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    MerchantPromocodeForm,
  },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-promocodes" }, title: "Промокоды" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PROMOCODE_CREATE,
          variables: {
            input: formData,
          },
        })
        .then(({ data }) => {
          if (data.createMerchantPromocode?.id) {
            this.$router.push({ name: "merchant-promocodes" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating merchant promocode:", error);
          const message = error.graphQLErrors?.[0]?.message || error.message || "Ошибка создания промокода";
          alert(message);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-promocodes" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-promocode-create-page {
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
