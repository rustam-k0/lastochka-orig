<template>
  <div class="t-header">
    <div class="t-header__search">
      <InputComponent
        class="t-header__input"
        :type="type"
        :placeholder="placeholder"
        v-model="filters.search"
        @update:model-value="updateFilters"
        small
      >
        <template #before>
          <IconComponent name="search-line" />
        </template>
      </InputComponent>
    </div>
    <div v-if="showFilters" class="t-header__filters">
      <div class="t-header__filters-col">
        <span>Статус</span>
        <SelectComponent
          v-model="filters.is_active"
          @update:model-value="updateFilters"
          :options="statusOptions"
          label-name="label"
          value-name="value"
          placeholder="Все"
          small
        />
      </div>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";

export default {
  mounted() {
    this.filters = { ...this.modelValue };
  },
  emits: ["update:model-value", "update:showFilters"],
  components: {
    InputComponent,
    IconComponent,
    SelectComponent
  },
  name: "TableHeader",
  props: {
    showFilters: {
      type: Boolean,
      default: false,
    },
    modelValue: {
      type: Object,
      default: () => {},
    },
    placeholder: String,
    type: {
      type: String,
      default: "text",
    },
  },
  data() {
    return {
      filters: {
        search: null,
        is_active: null,
      },
      statusOptions: [
        { label: "Активные", value: true },
        { label: "Неактивные", value: false },
      ],
    };
  },
  methods: {
    updateFilters() {
      this.$emit("update:model-value", this.filters);
    },
    updateFiltersState() {
      this.$emit("update:showFilters", !this.showFilters);
    },
  },
};
</script>

<style lang="stylus">
.t-header {
  width 100%
  display flex
  flex-direction column
  gap 16px
  padding 16px
  border-radius 10px
  border-bottom-left-radius 0
  border-bottom-right-radius 0
  background var(--white)

  &__search {
    display flex
    align-items center
    gap 8px
  }

  &__filters {
    display flex
    align-items center
    gap 16px
  }

  &__filters-col {
    display flex
    align-items center
    gap 8px
    font-size 0.813rem
    font-weight 500
    white-space nowrap
  }
}
</style>