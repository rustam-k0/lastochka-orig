<template>
  <div v-if="items.length" class="custom-checkboxes">
    <div v-for="item in items" :key="item.field" class="custom-checkboxes__row">
      <div class="custom-checkboxes__label">{{ item.label }}</div>
      <FlagCheckboxComponent
        :model-value="Boolean(modelValue && modelValue[item.field])"
        :disabled="disabled"
        @update:model-value="setValue(item.field, $event)"
        >{{ modelValue && modelValue[item.field] ? "Да" : "Нет" }}
      </FlagCheckboxComponent>
    </div>
  </div>
</template>

<script>
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";

export default {
  name: "CustomCheckboxesField",
  components: { FlagCheckboxComponent },
  props: {
    entity: { type: String, required: true },
    modelValue: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  emits: ["update:model-value"],
  computed: {
    items() {
      return useCheckboxConfigStore().customCheckboxes(this.entity);
    },
  },
  methods: {
    setValue(field, value) {
      this.$emit("update:model-value", { ...(this.modelValue || {}), [field]: value });
    },
  },
};
</script>

<style lang="stylus">
.custom-checkboxes {
  display: flex;
  flex-direction: column;
  gap: 12px;

  &__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }

  &__label {
    font-size: 0.875rem;
  }
}
</style>
