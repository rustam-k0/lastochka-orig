<template>
  <div class="default-modal operating-cost-modal">
    <ModalHeader :title="item ? 'Правило расхода' : 'Новое правило расхода'" @close="$emit('close')" />
    <div class="default-modal__body operating-cost-modal__body">
      <SelectComponent
        v-model="form.shop_id.value"
        :errors="form.shop_id.errors"
        :options="shops"
        label-name="title"
        title="Магазин"
        placeholder="Вся сеть"
        clearable
      />
      <SelectComponent
        v-model="form.category.value"
        :errors="form.category.errors"
        :options="categoryOptions"
        label-name="label"
        title="Категория расхода"
        placeholder="Выберите категорию"
        required
      />
      <SelectComponent
        v-model="form.rule_type.value"
        :errors="form.rule_type.errors"
        :options="ruleTypeOptions"
        label-name="label"
        title="Тип правила"
        placeholder="Выберите тип"
        required
      />
      <InputComponent
        v-model="form.value.value"
        :errors="form.value.errors"
        type="number"
        :title="valueTitle"
        placeholder="0"
        required
      />
      <InputComponent
        v-model="form.period_start.value"
        :errors="form.period_start.errors"
        type="date"
        title="Начало действия"
        required
      />
      <InputComponent
        v-model="form.period_end.value"
        :errors="form.period_end.errors"
        type="date"
        title="Конец действия (пусто = бессрочно)"
      />
      <InputComponent
        v-model="form.comment.value"
        :errors="form.comment.errors"
        title="Комментарий"
        placeholder="Необязательно"
      />
      <label class="operating-cost-modal__checkbox">
        <input type="checkbox" v-model="form.is_active.value" />
        Активно
      </label>
    </div>
    <div class="default-modal__footer operating-cost-modal__footer">
      <button class="btn btn--gray btn--md" @click.prevent="$emit('close')">Отмена</button>
      <button class="btn btn--main btn--md" :disabled="loading" @click.prevent="submit">Сохранить</button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CREATE from "@/graphql/mutations/OperatingCostCreate.graphql";
import UPDATE from "@/graphql/mutations/OperatingCostUpdate.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";

export default {
  name: "OperatingCostModal",
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
      categoryOptions: [
        { value: "rent", label: "Аренда" },
        { value: "payroll", label: "ФОТ" },
        { value: "marketing", label: "Маркетинг/реклама" },
        { value: "other", label: "Прочее" },
      ],
      ruleTypeOptions: [
        { value: "fixed_amount", label: "Фиксированная сумма, ₽ за период" },
        { value: "pct_of_revenue", label: "% от оборота" },
      ],
      form: {
        shop_id: { value: null, errors: [] },
        category: { value: null, errors: [] },
        rule_type: { value: null, errors: [] },
        value: { value: null, errors: [] },
        period_start: { value: null, errors: [] },
        period_end: { value: null, errors: [] },
        comment: { value: null, errors: [] },
        is_active: { value: true, errors: [] },
      },
    };
  },
  computed: {
    valueTitle() {
      return this.form.rule_type.value?.value === "pct_of_revenue"
        ? "Доля от оборота, %"
        : "Сумма, ₽ за период";
    },
  },
  mounted() {
    if (this.item) {
      this.form.shop_id.value = this.item.shop || null;
      this.form.category.value = this.categoryOptions.find((o) => o.value === this.item.category) || null;
      this.form.rule_type.value = this.ruleTypeOptions.find((o) => o.value === this.item.rule_type) || null;
      this.form.value.value = this.item.value;
      this.form.period_start.value = this.item.period_start;
      this.form.period_end.value = this.item.period_end;
      this.form.comment.value = this.item.comment;
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
        shop_id: this.form.shop_id.value ? parseInt(this.form.shop_id.value.id) : null,
        category: this.form.category.value?.value,
        rule_type: this.form.rule_type.value?.value,
        value:
          this.form.value.value !== null && this.form.value.value !== ""
            ? parseFloat(this.form.value.value)
            : undefined,
        period_start: this.form.period_start.value || undefined,
        period_end: this.form.period_end.value || null,
        comment: this.form.comment.value || null,
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
.operating-cost-modal {
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
