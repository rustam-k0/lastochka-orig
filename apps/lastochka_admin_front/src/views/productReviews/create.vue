<template>
  <div class="product-review-create">
    <!-- Заголовок -->
    <div class="product-review-create__header">
      <button type="button" class="product-review-create__back" @click="goBack">
        <IconComponent name="arrow-left-line" />
        <span>Назад</span>
      </button>
      <h1 class="product-review-create__title">Создание отзыва на товар</h1>
    </div>

    <!-- Форма -->
    <div class="product-review-create__content">
      <ProductReviewForm
        ref="form"
        :loading="saving"
        @submit="handleSubmit"
        @cancel="goBack"
      />
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import ProductReviewForm from "./components/ProductReviewForm.vue";
import PRODUCT_REVIEW_CREATE from "@/graphql/mutations/ProductReviewCreate.graphql";

export default {
  name: "ProductReviewCreate",
  inject: ["apollo"],
  components: {
    IconComponent,
    ProductReviewForm,
  },
  data() {
    return {
      saving: false,
    };
  },
  methods: {
    goBack() {
      if (this.$refs.form?.checkUnsavedChanges()) {
        this.$router.push({ name: "product-reviews" });
      }
    },
    handleSubmit(formData) {
      this.saving = true;

      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_REVIEW_CREATE,
          variables: {
            input: formData,
          },
        })
        .then(({ data }) => {
          if (data.createProductReview?.id) {
            this.$router.push({ name: "product-reviews" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating product review:", error);
          alert(error.message || "Ошибка создания отзыва");
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.product-review-create {
  &__header {
    display flex
    align-items center
    gap 16px
    margin-bottom 24px
  }

  &__back {
    display flex
    align-items center
    gap 8px
    padding 8px 12px
    border none
    background transparent
    font-size 0.875rem
    color var(--dark)
    cursor pointer
    border-radius 8px
    transition background 0.2s

    &:hover {
      background var(--gray-3)
    }

    svg {
      width 20px
      height 20px
    }
  }

  &__title {
    font-size 1.5rem
    font-weight 600
    color var(--dark)
    margin 0
  }

  &__content {
    background var(--white)
    border-radius 12px
    padding 24px
    box-shadow 0 1px 3px rgba(0, 0, 0, 0.1)
  }
}
</style>
