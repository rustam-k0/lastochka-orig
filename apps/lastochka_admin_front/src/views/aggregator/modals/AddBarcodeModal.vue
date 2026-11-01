<template>
  <div class="default-modal add-barcode-modal">
    <ModalHeader @close="$emit('close')" title="Добавить штрихкод" />
    <div class="default-modal__body">
      <InputComponent
        title="Штрихкод (EAN)"
        placeholder="Только цифры"
        required
        v-model="form.ean.value"
        :errors="form.ean.errors"
      />
    </div>
    <div class="add-barcode-modal__footer">
      <button @click="$emit('close')" class="btn btn--white btn--md">Отмена</button>
      <button @click="submit" class="btn btn--md" :disabled="saving">Добавить</button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import { useDefaultStore } from "@/stores/default.js";
import { parseGqlErrors, resetErrors } from "@/utils.js";
import ADD_BARCODE from "@/graphql/pages/Aggregator/addPlatformProductBarcode.graphql";

export default {
  name: "AddBarcodeModal",
  inject: ["apollo"],
  components: { InputComponent, ModalHeader },
  props: {
    callback: Function,
    platformProductId: Number,
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
          mutation: ADD_BARCODE,
          variables: {
            platformProductId: this.platformProductId,
            ean: this.form.ean.value,
          },
        })
        .then(() => {
          if (this.callback) this.callback();
          useDefaultStore().modals = [];
        })
        .catch((e) => {
          this.saving = false;
          if (e.graphQLErrors) {
            this.parseGqlErrors(e.graphQLErrors);
          }
          const msg = e.graphQLErrors?.[0]?.message;
          if (msg) this.form.ean.errors = [msg];
        });
    },
  },
};
</script>

<style lang="stylus">
.add-barcode-modal {
  width 460px

  &__footer {
    display flex
    gap 16px
    justify-content flex-end
    padding 16px
    border-top 1px solid var(--white-2)
  }
}
</style>
