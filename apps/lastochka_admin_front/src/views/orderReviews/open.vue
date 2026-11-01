<template>
  <div class="order-review-open">
    <!-- Загрузка -->
    <div v-if="loading" class="order-review-open__loading">
      <span>Загрузка...</span>
    </div>

    <!-- Контент -->
    <template v-else-if="review">
      <!-- Заголовок -->
      <div class="order-review-open__header">
        <button type="button" class="order-review-open__back" @click="goBack">
          <IconComponent name="arrow-left-line" />
          <span>Назад</span>
        </button>
        <h1 class="order-review-open__title">
          Отзыв на доставку № {{ reviewId }}
        </h1>
      </div>

      <!-- Режим просмотра -->
      <div class="order-review-open__view">
        <div class="order-review-open__card">
          <table class="order-review-open__table">
            <tr>
              <th>Заказ</th>
              <td>№ {{ review.order_number || review.order_id }}</td>
            </tr>
            <tr>
              <th>Оценка</th>
              <td>
                <StarRating :modelValue="review.rating" readonly small />
                <span class="order-review-open__rating-number">({{ review.rating }})</span>
              </td>
            </tr>
            <tr>
              <th>Автор</th>
              <td>{{ review.author_name || '—' }}</td>
            </tr>
            <tr v-if="review.author_phone">
              <th>Телефон</th>
              <td>{{ review.author_phone }}</td>
            </tr>
            <tr>
              <th>Дата создания</th>
              <td>{{ formatDateTime(review.created_at) }}</td>
            </tr>
            <tr>
              <th>Ошибка сборки</th>
              <td>{{ review.error_type_label || "—" }}</td>
            </tr>
          </table>

          <div v-if="review.description" class="order-review-open__description">
            <h3>Описание:</h3>
            <div class="order-review-open__description-text">
              {{ review.description }}
            </div>
          </div>

          <div class="order-review-open__error-type">
            <OrderReviewErrorTypeForm
              :review-id="review.id"
              :error-type="review.error_type"
              @success="onErrorTypeSaved"
            />
          </div>
        </div>
      </div>
    </template>

    <!-- Не найдено -->
    <div v-else class="order-review-open__not-found">
      <IconComponent name="file-list-3-line" />
      <p>Отзыв не найден</p>
      <router-link :to="{ name: 'order-reviews' }" class="btn btn--md">
        Вернуться к списку
      </router-link>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import StarRating from "@/views/merchantReviews/components/StarRating.vue";
import OrderReviewErrorTypeForm from "@/views/orderReviews/components/OrderReviewErrorTypeForm.vue";
import ORDER_REVIEW from "@/graphql/queries/order_review.graphql";

export default {
  name: "OrderReviewOpen",
  inject: ["apollo"],
  components: {
    IconComponent,
    StarRating,
    OrderReviewErrorTypeForm,
  },
  data() {
    return {
      review: null,
      loading: true,
    };
  },
  computed: {
    reviewId() {
      return this.$route.params.id;
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
          query: ORDER_REVIEW,
          variables: { id: parseInt(this.reviewId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.review = data.orderReview;
          if (!this.review) {
            console.error("Order review not found");
          }
        })
        .catch((error) => {
          console.error("Error fetching order review:", error);
          this.review = null;
        })
        .finally(() => {
          this.loading = false;
        });
    },
    onErrorTypeSaved(saved) {
      this.review = {
        ...this.review,
        error_type: saved.error_type,
        error_type_label: saved.error_type_label,
      };
    },
    goBack() {
      this.$router.push({ name: "order-reviews" });
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
.order-review-open {
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

  &__view {
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

  &__error-type {
    margin-top 24px
    padding-top 24px
    border-top 1px solid var(--gray-dark)
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