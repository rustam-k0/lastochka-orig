<template>
  <div class="default-modal client-bonus-modal">
    <ModalHeader title="Баллы клиента" @close="$emit('close')" />
    <div class="default-modal__body client-bonus-modal__body">
      <div class="client-bonus-modal__summary">
        <span
          >Баланс: <b>{{ balance }}</b></span
        >
        <span v-if="reserved > 0" class="client-bonus-modal__hint">
          зарезервировано под заказы: {{ reserved }}
        </span>
      </div>

      <div class="client-bonus-modal__switch">
        <button
          type="button"
          :class="['btn', 'btn--md', form.type.value === 'earn' ? 'btn--main' : 'btn--white']"
          @click="setType('earn')"
        >
          Начислить
        </button>
        <button
          type="button"
          :class="['btn', 'btn--md', form.type.value === 'spend' ? 'btn--main' : 'btn--white']"
          @click="setType('spend')"
        >
          Списать
        </button>
      </div>

      <InputComponent
        v-model.number="form.amount.value"
        :errors="form.amount.errors"
        title="Сумма в баллах"
        type="number"
        placeholder="Например, 500"
        required
      />
      <span v-if="form.type.value === 'spend'" class="client-bonus-modal__hint">
        Доступно к списанию: {{ available }}
      </span>

      <InputComponent
        v-model="form.comment.value"
        :errors="form.comment.errors"
        title="Комментарий"
        placeholder="За что начисляем или списываем"
        required
      />
      <span class="client-bonus-modal__hint">
        Комментарий увидят администраторы в истории операций, до 500 символов.
      </span>
    </div>
    <div class="default-modal__footer client-bonus-modal__footer">
      <button type="button" class="btn btn--gray btn--md" @click="$emit('close')">Отмена</button>
      <button type="button" class="btn btn--main btn--md" :disabled="loading" @click.prevent="submit">
        {{ form.type.value === "spend" ? "Списать" : "Начислить" }}
      </button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import BONUS_OPERATION from "@/graphql/pages/Client/clientBonusOperationCreate.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";

export default {
  name: "ClientBonusModal",
  inject: ["apollo"],
  components: { InputComponent, ModalHeader },
  props: {
    clientId: {
      type: Number,
      required: true,
    },
    balance: {
      type: Number,
      default: 0,
    },
    reserved: {
      type: Number,
      default: 0,
    },
    callback: Function,
  },
  data() {
    return {
      loading: false,
      form: {
        type: { value: "earn", errors: [] },
        amount: { value: null, errors: [] },
        comment: { value: null, errors: [] },
      },
    };
  },
  computed: {
    available() {
      return Math.max(0, this.balance - this.reserved);
    },
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    setType(type) {
      this.form.type.value = type;
      this.form.amount.errors = [];
    },
    validate() {
      let valid = true;
      const amount = parseInt(this.form.amount.value, 10);

      if (!amount || amount <= 0) {
        this.form.amount.errors.push("Укажите сумму больше нуля");
        valid = false;
      } else if (this.form.type.value === "spend" && amount > this.available) {
        this.form.amount.errors.push(`Доступно к списанию: ${this.available}`);
        valid = false;
      }

      const comment = (this.form.comment.value || "").trim();
      if (!comment) {
        this.form.comment.errors.push("Укажите комментарий к операции");
        valid = false;
      } else if (comment.length > 500) {
        this.form.comment.errors.push("Комментарий не длиннее 500 символов");
        valid = false;
      }

      return valid;
    },
    submit() {
      this.resetErrors();
      if (!this.validate() || this.loading) {
        return;
      }
      this.loading = true;

      this.apollo()
        .admin.mutate({
          mutation: BONUS_OPERATION,
          variables: {
            clientId: this.clientId,
            type: this.form.type.value,
            amount: parseInt(this.form.amount.value, 10),
            comment: this.form.comment.value.trim(),
          },
        })
        .then(() => {
          this.$emit("close");
          if (this.callback) {
            this.callback();
          }
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors || []);
          const plain = (graphQLErrors || []).find((err) => err.extensions?.category !== "validation");
          if (plain) {
            this.form.amount.errors.push(plain.message);
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
.client-bonus-modal {
  width 100%
  max-width 420px

  &__body {
    display flex
    flex-direction column
    gap 12px
  }

  &__summary {
    display flex
    flex-direction column
    gap 4px
  }

  &__switch {
    display flex
    gap 8px

    & .btn {
      flex 1
    }
  }

  &__hint {
    font-size 0.8125em
    color var(--gray-dark)
  }

  &__footer {
    display flex
    gap 16px

    & .btn {
      width 100%
    }
  }
}
</style>
