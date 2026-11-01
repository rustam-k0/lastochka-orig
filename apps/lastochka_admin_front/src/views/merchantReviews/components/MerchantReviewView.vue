<template>
  <div class="merchant-review-view">
    <div class="merchant-review-view__card">
      <table class="merchant-review-view__table">
        <tbody>
          <tr>
            <th>Магазин</th>
            <td>{{ review.shop?.title || '—' }}</td>
          </tr>
          <tr>
            <th>Оценка</th>
            <td>
              <StarRating :modelValue="review.rating" readonly showValue small />
            </td>
          </tr>
          <tr>
            <th>Статус</th>
            <td>
              <span
                class="merchant-review-view__status"
                :class="{ 'merchant-review-view__status--published': review.is_published }"
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
            <td>{{ formatDate(review.created_at) }}</td>
          </tr>
          <tr v-if="review.updated_at && review.updated_at !== review.created_at">
            <th>Дата обновления</th>
            <td>{{ formatDate(review.updated_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="review.description" class="merchant-review-view__description">
      <h3>Описание:</h3>
      <div class="merchant-review-view__description-text">
        {{ review.description }}
      </div>
    </div>
  </div>
</template>

<script>
import StarRating from "./StarRating.vue";
import dayjs from "dayjs";

export default {
  name: "MerchantReviewView",
  components: {
    StarRating,
  },
  props: {
    review: {
      type: Object,
      required: true,
    },
  },
  methods: {
    formatDate(date) {
      if (!date) return '—';
      return dayjs(date).format("DD.MM.YYYY HH:mm");
    },
  },
};
</script>

<style lang="stylus">
.merchant-review-view {
  &__card {
    background var(--white)
    border-radius 8px
    overflow hidden
  }

  &__table {
    width 100%
    border-collapse collapse

    th, td {
      padding 16px
      text-align left
      border-bottom 1px solid var(--gray-dark)
      font-size 0.875rem
    }

    th {
      width 200px
      color var(--dark-light)
      font-weight 500
      background var(--gray-3)
    }

    td {
      color var(--dark)
    }

    tr:last-child {
      th, td {
        border-bottom none
      }
    }
  }

  &__status {
    display inline-flex
    align-items center
    gap 6px
    padding 4px 12px
    border-radius 20px
    font-size 0.75rem
    font-weight 500
    background var(--gray-dark)
    color var(--dark)

    &::before {
      content ''
      width 8px
      height 8px
      border-radius 50%
      background var(--gray-2)
    }

    &--published {
      background rgba(76, 175, 80, 0.15)
      color var(--green)

      &::before {
        background var(--green)
      }
    }
  }

  &__description {
    margin-top 24px

    h3 {
      font-size 0.875rem
      font-weight 600
      color var(--dark)
      margin-bottom 12px
    }
  }

  &__description-text {
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    padding 16px
    font-size 0.875rem
    color var(--dark)
    line-height 1.6
    white-space pre-wrap
    word-wrap break-word
  }
}
</style>
