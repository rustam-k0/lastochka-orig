<template>
  <main class="page--main merchant-review-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-review-create-page__header">
      <h2>Создание отзыва</h2>
    </div>

    <div class="merchant-review-create-page__content">
      <MerchantReviewForm
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
import MerchantReviewForm from "./components/MerchantReviewForm.vue";
import MERCHANT_REVIEW_CREATE from "@/graphql/mutations/MerchantReviewCreate.graphql";

export default {
  name: "MerchantReviewCreatePage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    MerchantReviewForm,
  },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-reviews" }, title: "Отзывы на магазины" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;

      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_REVIEW_CREATE,
          variables: {
            input: formData,
          },
        })
        .then(({ data }) => {
          if (data.createMerchantReview?.id) {
            this.$router.push({ name: "merchant-reviews" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating merchant review:", error);
          alert(error.message || "Ошибка создания отзыва");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-reviews" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-review-create-page {
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
