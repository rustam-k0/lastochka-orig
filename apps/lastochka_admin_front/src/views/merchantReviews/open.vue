<template>
  <main class="page--main merchant-review-open-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />

    <!-- Загрузка -->
    <div v-if="loading && !review" class="merchant-review-open-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Не найден -->
    <div v-else-if="!review && !loading" class="merchant-review-open-page__not-found">
      <IconComponent name="file-unknow-line" />
      <h3>Отзыв не найден</h3>
      <button @click="goBack" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>

    <!-- Контент -->
    <template v-else>
      <div class="merchant-review-open-page__header">
        <h2>{{ isEditMode ? `Редактирование отзыва № ${review.id}` : `Отзыв № ${review.id}` }}</h2>
        <div v-if="isAdmin && !isEditMode" class="merchant-review-open-page__actions">
          <button @click="enableEdit" class="btn btn--md">
            <IconComponent name="pencil-line" />
            <span>Редактировать</span>
          </button>
        </div>
      </div>

      <div class="merchant-review-open-page__content">
        <!-- Режим просмотра -->
        <MerchantReviewView
          v-if="!isEditMode"
          :review="review"
        />

        <!-- Режим редактирования -->
        <MerchantReviewForm
          v-else
          :initialData="review"
          :loading="saving"
          :isEdit="true"
          :viewOnly="!isAdmin"
          @submit="handleSubmit"
          @cancel="handleCancel"
          ref="formRef"
        />
      </div>
    </template>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import MerchantReviewForm from "./components/MerchantReviewForm.vue";
import MerchantReviewView from "./components/MerchantReviewView.vue";
import MERCHANT_REVIEW from "@/graphql/queries/merchant_review.graphql";
import MERCHANT_REVIEW_UPDATE from "@/graphql/mutations/MerchantReviewUpdate.graphql";
import { useAuthStore } from "@/stores/auth.js";

export default {
  name: "MerchantReviewOpenPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantReviewForm,
    MerchantReviewView,
  },
  data() {
    return {
      review: null,
      loading: true,
      saving: false,
      isEditMode: false,
    };
  },
  computed: {
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles?.[0]?.name;
    },
    isAdmin() {
      return this.userRole === "admin";
    },
    reviewId() {
      return this.$route.params.id;
    },
    breadcrumbs() {
      const crumbs = [
        { to: { name: "merchant-reviews" }, title: "Отзывы на магазины" },
      ];

      if (this.isEditMode && this.review) {
        crumbs.push({ title: `Редактирование № ${this.review.id}` });
      } else if (this.review) {
        crumbs.push({ title: `Отзыв № ${this.review.id}` });
      }

      return crumbs;
    },
  },
  watch: {
    reviewId: {
      immediate: true,
      handler(id) {
        if (id) {
          this.get();
        }
      },
    },
    '$route.query.edit': {
      immediate: true,
      handler(edit) {
        this.isEditMode = edit === "true" && this.isAdmin;
      },
    },
  },
  beforeRouteLeave(to, from, next) {
    if (this.isEditMode && this.$refs.formRef?.checkUnsavedChanges) {
      if (this.$refs.formRef.checkUnsavedChanges()) {
        next();
      } else {
        next(false);
      }
    } else {
      next();
    }
  },
  methods: {
    get() {
      this.loading = true;

      this.apollo()
        .admin.query({
          query: MERCHANT_REVIEW,
          variables: {
            id: parseInt(this.reviewId),
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.merchantReview) {
            this.review = data.merchantReview;
          } else {
            this.review = null;
          }
        })
        .catch((error) => {
          console.error("Error fetching merchant review:", error);
          this.review = null;
        })
        .finally(() => {
          this.loading = false;
        });
    },
    enableEdit() {
      this.$router.replace({
        name: "merchant-review",
        params: { id: this.reviewId },
        query: { edit: "true" },
      });
    },
    handleSubmit(formData) {
      this.saving = true;

      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_REVIEW_UPDATE,
          variables: {
            id: parseInt(this.reviewId),
            input: formData,
          },
        })
        .then(({ data }) => {
          if (data.updateMerchantReview?.id) {
            this.$router.push({ name: "merchant-reviews" });
          } else {
            throw new Error("Ошибка сохранения");
          }
        })
        .catch((error) => {
          console.error("Error updating merchant review:", error);
          alert(error.message || "Ошибка сохранения отзыва");
        })
        .finally(() => {
          this.saving = false;
        });
    },
    handleCancel() {
      if (this.isEditMode) {
        this.$router.replace({
          name: "merchant-review",
          params: { id: this.reviewId },
        });
      } else {
        this.goBack();
      }
    },
    goBack() {
      this.$router.push({ name: "merchant-reviews" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-review-open-page {
  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px

    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }

  &__actions {
    display flex
    gap 12px
  }

  &__content {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
  }

  &__loading {
    display flex
    justify-content center
    padding 64px
  }

  &__not-found {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center

    .icon {
      width 64px
      height 64px
      margin-bottom 16px

      svg path {
        fill var(--gray-light)
      }
    }

    h3 {
      font-size 1.25rem
      color var(--dark)
      margin-bottom 24px
    }
  }
}
</style>
