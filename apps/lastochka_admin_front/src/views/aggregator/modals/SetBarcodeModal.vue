<template>
  <div class="default-modal set-barcode-modal">
    <ModalHeader @close="$emit('close')" title="Проставить штрихкод" />
    <div class="default-modal__body">
      <p class="set-barcode-modal__title">{{ incomingTitle }}</p>
      <InputComponent
        title="Штрихкод (EAN)"
        placeholder="Только цифры"
        required
        v-model="form.ean.value"
        :errors="form.ean.errors"
      />
      <p class="set-barcode-modal__hint">
        После сохранения товар будет повторно сопоставлен с платформенной карточкой.
      </p>
    </div>
    <div class="set-barcode-modal__footer">
      <button @click="$emit('close')" class="btn btn--white btn--md">Отмена</button>
      <button @click="submit" class="btn btn--md" :disabled="saving">Сохранить</button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import { useDefaultStore } from "@/stores/default.js";
import { parseGqlErrors, resetErrors } from "@/utils.js";
import RESOLVE_SET_BARCODE from "@/graphql/pages/Aggregator/resolveQuarantineSetBarcode.graphql";

export default {
  name: "SetBarcodeModal",
  inject: ["apollo"],
  components: { InputComponent, ModalHeader },
  props: {
    callback: Function,
    quarantineId: Number,
    incomingTitle: String,
  },
  data() {
    return {
      saving: false,
      form: {
        ean: { value: null, errors: [] },
      },
    };
  },
  methods: {
    parseGqlErrors,
    resetErrors,
    submit() {
      this.resetErrors();
      this.saving = true;
      this.apollo()
        .admin.mutate({
          mutation: RESOLVE_SET_BARCODE,
          variables: { id: this.quarantineId, ean: this.form.ean.value },
        })
        .then(() => {
          if (this.callback) this.callback();
          useDefaultStore().modals = [];
        })
        .catch((e) => {
          this.saving = false;
          const msg = e.graphQLErrors?.[0]?.message;
          if (msg) this.form.ean.errors = [msg];
        });
    },
  },
};
</script>

<style lang="stylus">
.set-barcode-modal {
  width 460px

  &__title {
    font-weight 500
    margin-bottom 12px
  }

  &__hint {
    margin-top 12px
    font-size 0.8125em
    color var(--gray)
  }

  &__footer {
    display flex
    gap 16px
    justify-content flex-end
    padding 16px
    border-top 1px solid var(--white-2)
  }
}
</style>
