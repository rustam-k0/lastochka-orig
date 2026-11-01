<template>
  <div class="default-modal sale-modal">
    <ModalHeader @close="$emit('close')" title="Скидка" />
    <form @submit.prevent="" class="default-modal__body sale-modal__form">
      <InputComponent v-model="form.sale.value" />
      <div class="sale-modal__footer">
        <button @click="$emit('close')" class="btn btn--white btn--md">Отмена</button>
        <button class="btn btn--md">Начислить</button>
      </div>
    </form>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";

export default {
  name: "SaleModal",
  components: { InputComponent, ModalHeader },
  props: {
    value: String,
  },
  data() {
    return {
      form: {
        sale: {
          value: this.value,
          errors: [],
        },
      },
    };
  },
};
</script>

<style lang="stylus">
.sale-modal {
  width 312px

  &__form {
    display flex
    flex-direction column
    gap 16px
  }

  &__footer {
    display flex
    gap 16px
    width 100%
    justify-content space-between

    & .btn {
      width 100%
    }
  }
}
</style>
