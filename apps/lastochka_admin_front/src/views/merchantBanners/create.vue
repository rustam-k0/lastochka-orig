<template>
  <main class="page--main merchant-banner-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-banner-create-page__header">
      <h2>Создание баннера</h2>
    </div>
    
    <div class="merchant-banner-create-page__content">
      <MerchantBannerForm
        :loading="loading"
        :isEdit="false"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import { gqlErrorText } from "@/utils.js";
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import MerchantBannerForm from "./components/MerchantBannerForm.vue";
import MERCHANT_BANNER_CREATE from "@/graphql/mutations/MerchantBannerCreate.graphql";

export default {
  name: "MerchantBannerCreatePage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    MerchantBannerForm,
  },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-banners" }, title: "Баннеры" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_BANNER_CREATE,
          variables: formData,
        })
        .then(({ data }) => {
          if (data.merchantBannerCreate?.id) {
            this.$router.push({ name: "merchant-banners" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating merchant banner:", error);
          alert(gqlErrorText(error, "Ошибка создания баннера"));
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-banners" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-banner-create-page {
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
