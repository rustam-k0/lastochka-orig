<template>
  <form class="order-review-error-form" @submit.prevent="submit">
    <SelectComponent
      v-model="form.error_type.value"
      :errors="form.error_type.errors"
      :options="errorTypes"
      label-name="label"
      title="Ошибка сборки"
      placeholder="Не ошибка сборки"
      clearable
    />
    <p class="order-review-error-form__hint">
      Тип ошибки попадает в отчёт «Качество сборки» и относится на сборщика
      заказа.
    </p>
    <div class="order-review-error-form__actions">
      <button
        type="submit"
        class="btn btn--main btn--md"
        :disabled="loading || !changed"
      >
        {{ loading ? "Сохранение..." : "Сохранить" }}
      </button>
      <span v-if="saved && !changed" class="order-review-error-form__saved">
        Сохранено
      </span>
    </div>
  </form>
</template>

<script>
import SelectComponent from "@/components/inputs/select/input.vue";
import ORDER_REVIEW_ERROR_TYPES from "@/graphql/queries/order_review_error_types.graphql";
import SET_ORDER_REVIEW_ERROR_TYPE from "@/graphql/mutations/SetOrderReviewErrorType.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";

export default {
  name: "OrderReviewErrorTypeForm",
  inject: ["apollo"],
  components: { SelectComponent },
  props: {
    reviewId: { type: Number, required: true },
    errorType: { type: String, default: null },
  },
  emits: ["success"],
  data() {
    return {
      loading: false,
      saved: false,
      errorTypes: [],
      savedValue: this.errorType,
      form: {
        error_type: {
          value: null,
          errors: [],
        },
      },
    };
  },
  computed: {
    selectedValue() {
      return this.form.error_type.value?.value ?? null;
    },
    changed() {
      return this.selectedValue !== this.savedValue;
    },
  },
  mounted() {
    this.fetchErrorTypes();
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    fetchErrorTypes() {
      this.apollo()
        .admin.query({ query: ORDER_REVIEW_ERROR_TYPES })
        .then(({ data }) => {
          this.errorTypes = data.orderReviewErrorTypes || [];
          this.form.error_type.value =
            this.errorTypes.find((t) => t.value === this.savedValue) || null;
        })
        .catch((error) => {
          console.error("Error fetching order review error types:", error);
        });
    },
    submit() {
      this.resetErrors();
      this.loading = true;
      this.saved = false;

      this.apollo()
        .admin.mutate({
          mutation: SET_ORDER_REVIEW_ERROR_TYPE,
          variables: {
            id: this.reviewId,
            error_type: this.selectedValue,
          },
        })
        .then(({ data }) => {
          const review = data.setOrderReviewErrorType;
          this.savedValue = review.error_type;
          this.saved = true;
          this.$emit("success", review);
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            this.parseGqlErrors(graphQLErrors);
          }
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.order-review-error-form {
  display flex
  flex-direction column
  gap 12px
  max-width 400px

  &__hint {
    margin 0
    font-size 0.8125rem
    color var(--dark-light)
  }

  &__actions {
    display flex
    align-items center
    gap 12px
  }

  &__saved {
    font-size 0.875rem
    color var(--green, #2e7d32)
  }
}
</style>
