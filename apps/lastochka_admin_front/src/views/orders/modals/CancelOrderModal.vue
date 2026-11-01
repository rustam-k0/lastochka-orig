<template>
  <div class="default-modal cancel-order-modal">
    <ModalHeader title="Отменить заказ" @close="$emit('close')" />
    <div class="default-modal__body cancel-order-modal__body">
      <p class="cancel-order-modal__lead">Укажите причину отмены заказа.</p>

      <SelectComponent
        title="Причина отмены"
        placeholder="Выберите причину"
        :options="selectOptions"
        v-model="selectedReason"
        label-name="title"
        required
      />

      <TextareaComponent
        v-if="isOtherSelected"
        title="Причина отмены (свой текст)"
        placeholder="Опишите причину"
        v-model="customReason"
        required
      />

      <div v-if="errorMessage" class="cancel-order-modal__error">
        {{ errorMessage }}
      </div>
    </div>

    <div class="cancel-order-modal__footer">
      <button @click="$emit('close')" class="btn btn--white btn--md" :disabled="submitting">Отмена</button>
      <button @click="submit" class="btn btn--md btn--red" :disabled="submitting || !isValid">
        <span v-if="!submitting">Подтвердить</span>
        <span v-else>Отменяется…</span>
      </button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import REASONS from "@/graphql/queries/order_cancel_reasons_active.graphql";
import ORDER_CANCEL from "@/graphql/mutations/OrderCancel.graphql";

const OTHER_REASON = { id: "other", title: "Другое" };

export default {
  name: "CancelOrderModal",
  inject: ["apollo"],
  components: { ModalHeader, SelectComponent, TextareaComponent },
  props: {
    orderId: {
      type: Number,
      required: true,
    },
    callback: Function,
  },
  data() {
    return {
      reasons: [],
      selectedReason: null,
      customReason: "",
      submitting: false,
      errorMessage: "",
    };
  },
  computed: {
    selectOptions() {
      return [...this.reasons, OTHER_REASON];
    },
    isOtherSelected() {
      return this.selectedReason?.id === OTHER_REASON.id;
    },
    isValid() {
      if (this.isOtherSelected) {
        return this.customReason.trim().length > 0;
      }
      return !!this.selectedReason?.id;
    },
  },
  mounted() {
    this.getReasons();
  },
  methods: {
    getReasons() {
      this.apollo()
        .admin.query({
          query: REASONS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.reasons = data.orderCancelReasons || [];
        })
        .catch((error) => {
          console.error("Error fetching order cancel reasons:", error);
        });
    },
    submit() {
      if (!this.isValid || this.submitting) return;

      this.errorMessage = "";
      this.submitting = true;

      const variables = {
        order_id: this.orderId,
        reason_id: this.isOtherSelected ? null : this.selectedReason.id,
        custom_reason: this.isOtherSelected ? this.customReason.trim() : null,
      };

      this.apollo()
        .purchase.mutate({
          mutation: ORDER_CANCEL,
          variables,
        })
        .then(() => {
          if (this.callback) {
            this.callback();
          }
          this.$emit("close");
        })
        .catch(({ graphQLErrors, message }) => {
          this.errorMessage = graphQLErrors?.[0]?.message ?? message ?? "Не удалось отменить заказ";
        })
        .finally(() => {
          this.submitting = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.cancel-order-modal {
  width 480px

  &__body {
    display flex
    flex-direction column
    gap 16px
    padding 16px
  }

  &__lead {
    font-size 0.9375em
    line-height 22px
    margin 0
  }

  &__error {
    padding 12px 14px
    background var(--red-light, #fff3f3)
    border 1px solid var(--red, #e53935)
    border-radius 8px
    color var(--red, #e53935)
    font-size 0.8125em
    line-height 18px
  }

  &__footer {
    display flex
    padding 16px
    gap 16px
    width 100%
    justify-content flex-end
    border-top: 1px solid var(--white-2);

    & .btn {
      width 100%
      max-width 132px
    }

    & .btn--red {
      background var(--red, #e53935)
      color var(--white)

      &:hover:not(:disabled) {
        background var(--red-dark, #c62828)
      }
    }
  }
}
</style>
