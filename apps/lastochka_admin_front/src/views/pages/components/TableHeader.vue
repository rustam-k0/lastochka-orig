<template>
  <div class="t-header">
    <div class="t-header__search">
      <InputComponent
        class="t-header__input"
        type="text"
        :placeholder="placeholder"
        v-model="filters.search"
        @update:model-value="updateFilters"
        small
      >
        <template #before>
          <IconComponent name="search-line" />
        </template>
      </InputComponent>
      <button
        type="button"
        class="btn btn--sm t-header__filters-toggle"
        :class="{ 'btn--white': !showFilters }"
        @click="updateFiltersState"
      >
        <IconComponent name="filter-3-line" />
        <span>Фильтры</span>
      </button>
    </div>
    <div v-if="showFilters" class="t-header__filters">
      <div class="t-header__filters-col">
        <span>Статус</span>
        <SelectComponent
          :model-value="selectedOption(statusOptions, filters.is_published)"
          @update:model-value="setFilter('is_published', $event)"
          :options="statusOptions"
          label-name="label"
          placeholder="Все"
          clearable
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
  name: "TableHeader",
  components: {
    InputComponent,
    IconComponent,
    SelectComponent,
  },
  emits: ["update:model-value", "update:showFilters"],
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
  },
  data() {
    return {
      filters: {
        search: null,
        is_published: null,
      },
      statusOptions: [
        { label: "Опубликованные", value: true },
        { label: "Не опубликованные", value: false },
      ],
    };
  },
  mounted() {
    this.filters = { ...this.filters, ...this.modelValue };
  },
  methods: {
    // SelectComponent отдаёт объект опции целиком, а в фильтрах
    // хранится и уходит в запрос само значение.
    selectedOption(options, value) {
      if (value === null || value === undefined) {
        return null;
      }
      return options.find((item) => item.value === value) || null;
    },
    setFilter(field, option) {
      this.filters[field] = option ? option.value : null;
      this.updateFilters();
    },
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

  &__filters-toggle {
    white-space nowrap
    height 40px

    .icon {
      width 16px
      height 16px
    }
  }

  &__filters {
    display flex
    align-items center
    gap 16px
    flex-wrap wrap
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
