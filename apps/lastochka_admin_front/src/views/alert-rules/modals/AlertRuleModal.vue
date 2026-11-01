<template>
  <div class="default-modal alert-rule-modal">
    <ModalHeader :title="item ? 'Правило сигнала' : 'Новое правило сигнала'" @close="$emit('close')" />
    <div class="default-modal__body alert-rule-modal__body">
      <InputComponent
        v-model="form.name.value"
        :errors="form.name.errors"
        title="Название правила"
        placeholder="Например: Падение среднего чека"
        required
      />
      <InputComponent
        v-model="form.metric_key.value"
        :errors="form.metric_key.errors"
        title="Ключ метрики"
        placeholder="Например: avg_check"
        required
      />
      <SelectComponent
        v-model="form.comparison.value"
        :errors="form.comparison.errors"
        :options="comparisonOptions"
        label-name="label"
        title="Условие срабатывания"
        placeholder="Выберите условие"
        required
      />
      <InputComponent
        v-model="form.threshold_value.value"
        :errors="form.threshold_value.errors"
        type="number"
        title="Порог"
        placeholder="0"
        required
      />
      <InputComponent
        v-model="form.recommended_action_text.value"
        :errors="form.recommended_action_text.errors"
        title="Рекомендуемое действие"
        placeholder="Что делать при срабатывании"
      />
      <SelectComponent
        v-model="form.shop_id.value"
        :errors="form.shop_id.errors"
        :options="shops"
        label-name="title"
        title="Магазин"
        placeholder="Все магазины"
        clearable
      />
      <label class="alert-rule-modal__checkbox">
        <input type="checkbox" v-model="form.is_active.value" />
        Активно
      </label>
    </div>
    <div class="default-modal__footer alert-rule-modal__footer">
      <button class="btn btn--gray btn--md" @click.prevent="$emit('close')">Отмена</button>
      <button class="btn btn--main btn--md" :disabled="loading" @click.prevent="submit">Сохранить</button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CREATE from "@/graphql/mutations/ShopAlertRuleCreate.graphql";
import UPDATE from "@/graphql/mutations/ShopAlertRuleUpdate.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";

export default {
  name: "AlertRuleModal",
  inject: ["apollo"],
  components: { ModalHeader, InputComponent, SelectComponent },
  props: {
    item: { type: Object, default: null },
    shops: { type: Array, default: () => [] },
    // Вызывается после успешного сохранения — родительский список перечитывает данные.
    // Паттерн соответствует остальным модалкам проекта (NewStaffModal, AddClientModal
    // и т.п.), которые открываются через useDefaultStore().modals.push(...).
    callback: { type: Function, default: null },
  },
  emits: ["success", "close"],
  data() {
    return {
      loading: false,
      comparisonOptions: [
        { value: "lt", label: "Меньше порога" },
        { value: "lte", label: "Меньше или равно порогу" },
        { value: "gt", label: "Больше порога" },
        { value: "gte", label: "Больше или равно порогу" },
      ],
      form: {
        name: { value: null, errors: [] },
        metric_key: { value: null, errors: [] },
        comparison: { value: null, errors: [] },
        threshold_value: { value: null, errors: [] },
        recommended_action_text: { value: null, errors: [] },
        shop_id: { value: null, errors: [] },
        is_active: { value: true, errors: [] },
      },
    };
  },
  mounted() {
    if (this.item) {
      this.form.name.value = this.item.name;
      this.form.metric_key.value = this.item.metric_key;
      this.form.comparison.value = this.comparisonOptions.find((o) => o.value === this.item.comparison) || null;
      this.form.threshold_value.value = this.item.threshold_value;
      this.form.recommended_action_text.value = this.item.recommended_action_text;
      this.form.shop_id.value = this.item.shop || null;
      this.form.is_active.value = !!this.item.is_active;
    }
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    submit() {
      this.resetErrors();
      this.loading = true;

      const variables = {
        name: this.form.name.value || undefined,
        metric_key: this.form.metric_key.value || undefined,
        comparison: this.form.comparison.value?.value,
        threshold_value:
          this.form.threshold_value.value !== null && this.form.threshold_value.value !== ""
            ? parseFloat(this.form.threshold_value.value)
            : undefined,
        recommended_action_text: this.form.recommended_action_text.value || null,
        shop_id: this.form.shop_id.value ? parseInt(this.form.shop_id.value.id) : null,
        is_active: !!this.form.is_active.value,
      };
      if (this.item) {
        variables.id = parseInt(this.item.id);
      }

      this.apollo()
        .admin.mutate({ mutation: this.item ? UPDATE : CREATE, variables })
        .then(() => {
          if (this.callback) this.callback();
          this.$emit("success");
          this.$emit("close");
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) this.parseGqlErrors(graphQLErrors);
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus" scoped>
.alert-rule-modal {
  width 100%
  max-width 480px

  &__body {
    display flex
    flex-direction column
    gap 16px
  }

  &__checkbox {
    display flex
    align-items center
    gap 8px
    cursor pointer
  }

  &__footer {
    justify-content flex-end
  }
}
</style>
