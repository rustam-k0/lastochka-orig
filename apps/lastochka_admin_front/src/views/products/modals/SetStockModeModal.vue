<template>
  <div class="default-modal set-stock-mode-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Режим остатков ({{ count }} товар{{ countSuffix }})</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <form @submit.prevent="submit" class="default-modal__body set-stock-mode-modal__body">
      <SelectComponent
        title="Режим остатков"
        placeholder="Выберите режим"
        v-model="form.stock_mode.value"
        :errors="form.stock_mode.errors"
        :options="stockModeOptions"
        clearable
      />
      <template v-if="form.stock_mode.value && form.stock_mode.value.id === 'fixed'">
        <InputComponent
          title="Лимит остатков"
          type="number"
          placeholder="Введите количество"
          v-model="form.fixed_stock_quantity.value"
          :errors="form.fixed_stock_quantity.errors"
        />
        <SelectComponent
          title="Единица измерения"
          placeholder="Выберите единицу"
          v-model="form.fixed_stock_unit_type_id.value"
          :errors="form.fixed_stock_unit_type_id.errors"
          :options="stockUnits"
        />
      </template>
    </form>
    <div class="default-modal__footer set-stock-mode-modal__footer">
      <button type="button" @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button type="button" @click="submit" :disabled="loading" class="btn btn--main btn--md">
        {{ loading ? "Сохранение…" : "Применить" }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import SET_PRODUCTS_STOCK_MODE from "@/graphql/mutations/SetProductsStockMode.graphql";

export default {
  name: "SetStockModeModal",
  inject: ["apollo"],
  components: { IconComponent, SelectComponent, InputComponent },
  emits: ["close", "success"],
  props: {
    ids: {
      type: Array,
      default: () => [],
    },
    callback: {
      type: Function,
      default: null,
    },
  },
  data() {
    return {
      loading: false,
      stockModeOptions: [
        { id: null, title: "По категории (сбросить)" },
        { id: "ignore", title: "Не учитывать" },
        { id: "fixed", title: "Фиксированный лимит" },
      ],
      stockUnits: [
        { id: 2, title: "грамм" },
        { id: 1, title: "штука" },
      ],
      form: {
        stock_mode: { value: null, errors: [] },
        fixed_stock_quantity: { value: null, errors: [] },
        fixed_stock_unit_type_id: { value: null, errors: [] },
      },
    };
  },
  computed: {
    count() {
      return this.ids.length;
    },
    countSuffix() {
      const n = this.count % 100;
      const n1 = this.count % 10;
      if (n >= 11 && n <= 19) return "ов";
      if (n1 === 1) return "";
      if (n1 >= 2 && n1 <= 4) return "а";
      return "ов";
    },
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    submit() {
      this.resetErrors();
      const selectedMode = this.form.stock_mode.value;
      const stockMode = selectedMode ? selectedMode.id : null;
      const isFixed = stockMode === "fixed";
      const rawQty = this.form.fixed_stock_quantity.value;
      const fixedQty = isFixed && rawQty !== null && rawQty !== "" ? parseInt(rawQty) : null;
      const fixedUnitId = isFixed ? (this.form.fixed_stock_unit_type_id.value?.id ?? null) : null;
      this.loading = true;
      this.apollo()
        .admin.mutate({
          mutation: SET_PRODUCTS_STOCK_MODE,
          variables: {
            ids: this.ids.map((id) => parseInt(id)),
            stock_mode: stockMode,
            fixed_stock_quantity: fixedQty,
            fixed_stock_unit_type_id: fixedUnitId,
          },
        })
        .then(() => {
          this.$emit("success");
          if (this.callback) {
            this.callback();
          }
          this.$emit("close");
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
.set-stock-mode-modal {
  width 400px

  &__body {
    display flex
    flex-direction column
    gap 16px
  }

  &__footer {
    display flex
    gap 16px

    .btn {
      flex 1
    }
  }
}
</style>
