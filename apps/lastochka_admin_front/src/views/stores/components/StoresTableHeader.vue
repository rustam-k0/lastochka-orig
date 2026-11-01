<template>
  <div class="stores-filter">
    <div class="stores-filter__search">
      <InputComponent
        class="stores-filter__input"
        v-model="filters.search"
        @update:model-value="updateFilters"
        placeholder="Поиск по названию, адресу и коду 1С"
      >
        <template #before>
          <IconComponent name="search-line" />
        </template>
      </InputComponent>
      <SelectComponent
        class="stores-filter__active"
        v-model="activeFilter"
        :options="activeOptions"
        label-name="title"
        placeholder="Все магазины"
        @update:model-value="onActiveChange"
      />
      <label class="stores-filter__flag">
        <CheckboxComponent
          :model-value="filters.delivery_enabled === true"
          @update:model-value="onFlagChange('delivery_enabled', $event)"
        />
        <span>Доставка включена</span>
      </label>
      <label class="stores-filter__flag">
        <CheckboxComponent
          :model-value="filters.pickup_enabled === true"
          @update:model-value="onFlagChange('pickup_enabled', $event)"
        />
        <span>Самовывоз включён</span>
      </label>
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";

const ACTIVE_OPTIONS = [
  { id: "all", title: "Все магазины", value: null },
  { id: "active", title: "Активные", value: true },
  { id: "inactive", title: "Неактивные", value: false },
];

const defaultFilters = () => ({
  search: null,
  active: null,
  delivery_enabled: null,
  pickup_enabled: null,
});

export default {
  name: "StoresTableHeader",
  components: { IconComponent, InputComponent, SelectComponent, CheckboxComponent },
  emits: ["update:model-value"],
  props: {
    modelValue: Object,
  },
  mounted() {
    this.filters = { ...defaultFilters(), ...(this.modelValue || {}) };
    this.syncActiveFilterFromValue(this.filters.active ?? null);
  },
  data() {
    return {
      filters: defaultFilters(),
      activeFilter: ACTIVE_OPTIONS[0],
      activeOptions: ACTIVE_OPTIONS,
    };
  },
  methods: {
    updateFilters() {
      this.$emit("update:model-value", { ...this.filters });
    },
    onActiveChange(selected) {
      const option = selected || ACTIVE_OPTIONS[0];
      this.activeFilter = option;
      this.filters.active = option.value;
      this.updateFilters();
    },
    onFlagChange(key, value) {
      this.filters[key] = value ? true : null;
      this.updateFilters();
    },
    syncActiveFilterFromValue(value) {
      const match = ACTIVE_OPTIONS.find((opt) => opt.value === value);
      this.activeFilter = match || ACTIVE_OPTIONS[0];
    },
  },
};
</script>

<style lang="stylus">
.stores-filter {
  display flex
  flex-direction column
  gap 16px

  &__search {
    display flex
    align-items center
    flex-wrap wrap
    gap 12px
  }

  &__input {
    min-width 280px
    flex 1 1 320px
    max-width 420px
  }

  &__active {
    min-width 200px
    max-width 220px
  }

  &__flag {
    display flex
    align-items center
    gap 6px
    cursor pointer
    font-weight 500
    font-size 0.813rem
    white-space nowrap
  }
}
</style>
