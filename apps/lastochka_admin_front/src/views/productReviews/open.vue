<template>
  <div class="product-review-open">
    <!-- Загрузка -->
    <div v-if="loading" class="product-review-open__loading">
      <span>Загрузка...</span>
    </div>

    <!-- Контент -->
    <template v-else-if="review">
      <!-- Заголовок -->
      <div class="product-review-open__header">
        <button type="button" class="product-review-open__back" @click="goBack">
          <IconComponent name="arrow-left-line" />
          <span>Назад</span>
        </button>
        <h1 class="product-review-open__title">
          {{ isEditMode ? 'Редактирование отзыва' : 'Отзыв' }} № {{ reviewId }}
        </h1>
        <button
          v-if="!isEditMode && isAdmin"
          type="button"
          class="btn btn--white btn--md"
          @click="enableEdit"
        >
          <IconComponent name="pencil-line" />
          <span>Редактировать</span>
        </button>
      </div>

      <!-- Режим просмотра -->
      <div v-if="!isEditMode" class="product-review-open__view">
        <div class="product-review-open__card">
          <table class="product-review-open__table">
            <tr>
              <th>Магазин</th>
              <td>{{ review.shop?.title || '—' }}</td>
            </tr>
            <tr>
              <th>Товар</th>
              <td>{{ review.product?.title || '—' }}</td>
            </tr>
            <tr>
              <th>Оценка</th>
              <td>
                <StarRating :modelValue="review.rating" readonly small />
                <span class="product-review-open__rating-number">({{ review.rating }})</span>
              </td>
            </tr>
            <tr>
              <th>Статус</th>
              <td>
                <span
                  class="product-review-open__status"
                  :class="{ 'product-review-open__status--published': review.is_published }"
                >
                  {{ review.is_published ? 'Опубликован' : 'Черновик' }}
                </span>
              </td>
            </tr>
            <tr>
              <th>Автор</th>
              <td>{{ review.author_name || '—' }}</td>
            </tr>
            <tr>
              <th>Телефон</th>
              <td>{{ review.author_phone || '—' }}</td>
            </tr>
            <tr>
              <th>Дата создания</th>
              <td>{{ formatDateTime(review.created_at) }}</td>
            </tr>
          </table>

          <div v-if="review.description" class="product-review-open__description">
            <h3>Описание:</h3>
            <div class="product-review-open__description-text">
              {{ review.description }}
            </div>
          </div>
        </div>
      </div>

      <!-- Режим редактирования -->
      <div v-else class="product-review-open__edit">
        <ProductReviewForm
          ref="form"
          :initial-data="review"
          :loading="saving"
          :is-edit="true"
          :view-only="!isAdmin"
          @submit="handleSubmit"
          @cancel="cancelEdit"
        />
      </div>
    </template>

    <!-- Не найдено -->
    <div v-else class="product-review-open__not-found">
      <IconComponent name="file-list-3-line" />
      <p>Отзыв не найден</p>
      <router-link :to="{ name: 'product-reviews' }" class="btn btn--md">
        Вернуться к списку
      </router-link>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import ProductReviewForm from "./components/ProductReviewForm.vue";
import StarRating from "@/views/merchantReviews/components/StarRating.vue";
import PRODUCT_REVIEW from "@/graphql/queries/product_review.graphql";
import PRODUCT_REVIEW_UPDATE from "@/graphql/mutations/ProductReviewUpdate.graphql";
import { useAuthStore } from "@/stores/auth.js";

export default {
  name: "ProductReviewOpen",
  inject: ["apollo"],
  components: {
    IconComponent,
    ProductReviewForm,
    StarRating,
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
    reviewId() {
      return this.$route.params.id;
    },
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles?.[0]?.name;
    },
    isAdmin() {
      return this.userRole === "admin";
    },
  },
  watch: {
    "$route.query.edit": {
      immediate: true,
      handler(val) {
        this.isEditMode = val === "true" && this.isAdmin;
      },
    },
  },
  mounted() {
    this.fetchReview();
  },
  methods: {
    fetchReview() {
      this.loading = true;

      this.apollo()
        .admin.query({
          query: PRODUCT_REVIEW,
          variables: { id: parseInt(this.reviewId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.review = data.productReview;
          if (!this.review) {
            console.error("Product review not found");
          }
        })
        .catch((error) => {
          console.error("Error fetching product review:", error);
          this.review = null;
        })
        .finally(() => {
          this.loading = false;
        });
    },
    goBack() {
      if (this.isEditMode && this.$refs.form) {
        if (this.$refs.form.checkUnsavedChanges()) {
          this.$router.push({ name: "product-reviews" });
        }
      } else {
        this.$router.push({ name: "product-reviews" });
      }
    },
    enableEdit() {
      this.$router.push({
        name: "product-review",
        params: { id: this.reviewId },
        query: { edit: "true" },
      });
    },
    cancelEdit() {
      this.$router.push({
        name: "product-review",
        params: { id: this.reviewId },
      });
    },
    handleSubmit(formData) {
      this.saving = true;

      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_REVIEW_UPDATE,
          variables: {
            input: {
              id: parseInt(this.reviewId),
              ...formData,
            },
          },
        })
        .then(({ data }) => {
          if (data.updateProductReview?.id) {
            this.$router.push({ name: "product-reviews" });
          } else {
            throw new Error("Ошибка сохранения");
          }
        })
        .catch((error) => {
          console.error("Error updating product review:", error);
          alert(error.message || "Ошибка сохранения отзыва");
        })
        .finally(() => {
          this.saving = false;
        });
    },
    formatDateTime(dateString) {
      if (!dateString) return "—";
      const date = new Date(dateString);
      return date.toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    },
  },
};
</script>

<style lang="stylus">
.product-review-open {
  &__loading {
    display flex
    justify-content center
    padding 64px
    color var(--dark-light)
  }

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
    flex 1
    font-size 1.5rem
    font-weight 600
    color var(--dark)
    margin 0
  }

  &__view,
  &__edit {
    background var(--white)
    border-radius 12px
    padding 24px
    box-shadow 0 1px 3px rgba(0, 0, 0, 0.1)
  }

  &__card {
    max-width 600px
  }

  &__table {
    width 100%
    border-collapse collapse

    tr {
      border-bottom 1px solid var(--gray-dark)
    }

    th,
    td {
      padding 12px 0
      text-align left
      vertical-align top
    }

    th {
      width 150px
      font-weight 600
      font-size 0.875rem
      color var(--dark-light)
    }

    td {
      font-size 0.875rem
      color var(--dark)
    }
  }

  &__rating-number {
    margin-left 8px
    color var(--dark-light)
  }

  &__status {
    display inline-block
    padding 4px 8px
    border-radius 4px
    font-size 0.75rem
    font-weight 500
    background var(--gray-3)
    color var(--dark-light)

    &--published {
      background var(--green-light, #e6f7e6)
      color var(--green, #28a745)
    }
  }

  &__description {
    margin-top 24px
    padding-top 24px
    border-top 1px solid var(--gray-dark)

    h3 {
      font-size 0.875rem
      font-weight 600
      color var(--dark-light)
      margin 0 0 12px
    }
  }

  &__description-text {
    font-size 0.875rem
    color var(--dark)
    line-height 1.6
    white-space pre-wrap
  }

  &__not-found {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px 24px
    color var(--dark-light)

    svg {
      width 48px
      height 48px
      margin-bottom 16px
      opacity 0.5
    }

    p {
      margin 0 0 24px
      font-size 1rem
    }
  }
}
</style>
