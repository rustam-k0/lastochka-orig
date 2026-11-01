<template>
  <div class="client-filter">
    <div class="client-filter__search">
      <InputComponent
        v-model="filters.search"
        @update:model-value="updateFilters"
        placeholder="Поиск по имени, телефону и email"
        small
      >
        <template #before>
          <IconComponent name="search-line" />
        </template>
      </InputComponent>
      <div v-if="isB2bModuleEnabled" class="client-filter__b2b">
        <SelectComponent
          v-model="b2bFilter"
          :options="b2bOptions"
          label-name="title"
          placeholder="Все клиенты"
          @update:model-value="onB2bFilterChange"
        />
      </div>
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

const B2B_OPTIONS = [
  { id: "all", title: "Все клиенты", value: null },
  { id: "b2b", title: "Только B2B", value: true },
  { id: "b2c", title: "Только B2C", value: false },
];

export default {
  name: "ClientsTableHeader",
  components: { IconComponent, InputComponent, SelectComponent },
  props: {
    modelValue: Object,
  },
  mounted() {
    this.filters = { ...this.modelValue };
    this.syncB2bFilterFromValue(this.filters.is_b2b ?? null);
  },
  data() {
    return {
      filters: {
        search: null,
        is_b2b: null,
      },
      b2bFilter: B2B_OPTIONS[0],
      b2bOptions: B2B_OPTIONS,
    };
  },
  computed: {
    isB2bModuleEnabled() {
      return useModuleConfigStore().isModuleEnabled("b2b");
    },
  },
  methods: {
    updateFilters() {
      this.$emit("update:model-value", { ...this.filters });
    },
    onB2bFilterChange(selected) {
      const option = selected || B2B_OPTIONS[0];
      this.b2bFilter = option;
      this.filters.is_b2b = option.value;
      this.updateFilters();
    },
    syncB2bFilterFromValue(value) {
      const match = B2B_OPTIONS.find((opt) => opt.value === value);
      this.b2bFilter = match || B2B_OPTIONS[0];
    },
  },
};
</script>

<style lang="stylus">
.client-filter {
  display flex
  flex-direction column
  gap 16px

  &__search {
    display flex
    align-items center
    gap 8px
  }

  &__b2b {
    min-width 200px
  }

}
</style>
