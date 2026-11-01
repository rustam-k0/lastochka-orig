<template>
  <div class="default-modal kpi-target-modal">
    <ModalHeader :title="item ? 'Плановый показатель' : 'Новый плановый показатель'" @close="$emit('close')" />
    <div class="default-modal__body kpi-target-modal__body">
      <InputComponent
        v-model="form.metric_key.value"
        :errors="form.metric_key.errors"
        title="Ключ метрики"
        placeholder="Например: avg_check, revenue, gross_margin_pct"
        required
      />
      <SelectComponent
        v-model="form.shop_id.value"
        :errors="form.shop_id.errors"
        :options="shops"
        label-name="title"
        title="Магазин"
        placeholder="Вся сеть"
        clearable
      />
      <InputComponent
        v-model="form.period_start.value"
        :errors="form.period_start.errors"
        type="date"
        title="Начало периода"
        required
      />
      <InputComponent
        v-model="form.period_end.value"
        :errors="form.period_end.errors"
        type="date"
        title="Конец периода"
        required
      />
      <InputComponent
        v-model="form.target_value.value"
        :errors="form.target_value.errors"
        type="number"
        title="Целевое значение"
        placeholder="0"
        required
      />
      <InputComponent
        v-model="form.comment.value"
        :errors="form.comment.errors"
        title="Комментарий"
        placeholder="Необязательно"
      />
      <label class="kpi-target-modal__checkbox">
        <input type="checkbox" v-model="form.is_active.value" />
        Активно
      </label>
    </div>
    <div class="default-modal__footer kpi-target-modal__footer">
      <button class="btn btn--gray btn--md" @click.prevent="$emit('close')">Отмена</button>
      <button class="btn btn--main btn--md" :disabled="loading" @click.prevent="submit">Сохранить</button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CREATE from "@/graphql/mutations/KpiTargetCreate.graphql";
import UPDATE from "@/graphql/mutations/KpiTargetUpdate.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";

export default {
  name: "KpiTargetModal",
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
      form: {
        metric_key: { value: null, errors: [] },
        shop_id: { value: null, errors: [] },
        period_start: { value: null, errors: [] },
        period_end: { value: null, errors: [] },
        target_value: { value: null, errors: [] },
        comment: { value: null, errors: [] },
        is_active: { value: true, errors: [] },
      },
    };
  },
  mounted() {
    if (this.item) {
      this.form.metric_key.value = this.item.metric_key;
      this.form.shop_id.value = this.item.shop || null;
      this.form.period_start.value = this.item.period_start;
      this.form.period_end.value = this.item.period_end;
      this.form.target_value.value = this.item.target_value;
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
        metric_key: this.form.metric_key.value || undefined,
        shop_id: this.form.shop_id.value ? parseInt(this.form.shop_id.value.id) : null,
        period_start: this.form.period_start.value || undefined,
        period_end: this.form.period_end.value || undefined,
        target_value:
          this.form.target_value.value !== null && this.form.target_value.value !== ""
            ? parseFloat(this.form.target_value.value)
            : undefined,
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
.kpi-target-modal {
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
