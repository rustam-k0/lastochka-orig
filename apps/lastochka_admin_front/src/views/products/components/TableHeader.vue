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
      <CategoryTreeSelect
        class="t-header__category-filter"
        placeholder="Категория"
        v-model="selectedCategory"
        :options="categoriesTree"
        leaf-only
        clearable
        @update:model-value="handleCategoryChange"
      />
      <SelectComponent
        class="t-header__img-filter"
        placeholder="Все товары"
        v-model="selectedImgFilter"
        :options="imgFilterOptions"
        clearable
        small
        @update:model-value="handleImgFilterChange"
      />
      <SelectComponent
        class="t-header__fill-filter"
        placeholder="Заполненность"
        v-model="selectedFillFilter"
        :options="fillFilterOptions"
        clearable
        small
        @update:model-value="handleFillFilterChange"
      />
      <SelectComponent
        class="t-header__flag-filter"
        placeholder="Новинка: все"
        v-model="selectedIsNewFilter"
        :options="isNewFilterOptions"
        clearable
        small
        @update:model-value="handleFlagFilterChange('is_new', $event)"
      />
      <SelectComponent
        class="t-header__flag-filter"
        placeholder="Успей купить: все"
        v-model="selectedIsHurryUpFilter"
        :options="isHurryUpFilterOptions"
        clearable
        small
        @update:model-value="handleFlagFilterChange('is_hurry_up', $event)"
      />
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import CategoryTreeSelect from "@/components/inputs/CategoryTreeSelect.vue";
import SelectComponent from "@/components/inputs/select/input.vue";

export default {
  mounted() {
    this.filters = { ...this.modelValue };
    if (this.modelValue?.categories?.length) {
      this.selectedCategory = this.modelValue.categories[0];
    }
    if (this.modelValue?.has_img === true) {
      this.selectedImgFilter = this.imgFilterOptions[0];
    } else if (this.modelValue?.has_img === false) {
      this.selectedImgFilter = this.imgFilterOptions[1];
    }
    if (this.modelValue?.fill_percent_gte != null || this.modelValue?.fill_percent_lt != null) {
      const gte = this.modelValue?.fill_percent_gte ?? null;
      const lt = this.modelValue?.fill_percent_lt ?? null;
      this.selectedFillFilter = this.fillFilterOptions.find((o) => o.gte === gte && o.lt === lt) ?? null;
    }
    this.selectedIsNewFilter = this.flagFilterOption(this.isNewFilterOptions, this.modelValue?.is_new);
    this.selectedIsHurryUpFilter = this.flagFilterOption(
      this.isHurryUpFilterOptions,
      this.modelValue?.is_hurry_up
    );
  },
  emits: ["update:model-value", "update:showFilters"],
  components: { CategoryTreeSelect, InputComponent, IconComponent, SelectComponent },
  name: "SearchComponent",
  props: {
    showFilters: {
      type: Boolean,
      default: false,
    },
    modelValue: {
      type: Object,
      default: () => {},
    },
    categoriesTree: {
      type: Array,
      default: () => [],
    },
    placeholder: String,
    type: {
      type: String,
      default: "text",
    },
  },
  data() {
    return {
      selectedCategory: null,
      selectedImgFilter: null,
      imgFilterOptions: [
        { id: "with", title: "С фото" },
        { id: "without", title: "Без фото" },
      ],
      selectedFillFilter: null,
      fillFilterOptions: [
        { id: "0-25", title: "Заполнено 0-25%", gte: 0, lt: 25 },
        { id: "25-50", title: "Заполнено 25-50%", gte: 25, lt: 50 },
        { id: "50-75", title: "Заполнено 50-75%", gte: 50, lt: 75 },
        { id: "75-100", title: "Заполнено 75-100%", gte: 75, lt: null },
      ],
      // Галочки «Новинка» / «Успей купить»: пустой выбор = все товары
      selectedIsNewFilter: null,
      isNewFilterOptions: [
        { id: "yes", title: "Новинка: да", value: true },
        { id: "no", title: "Новинка: нет", value: false },
      ],
      selectedIsHurryUpFilter: null,
      isHurryUpFilterOptions: [
        { id: "yes", title: "Успей купить: да", value: true },
        { id: "no", title: "Успей купить: нет", value: false },
      ],
      filters: {
        search: null,
      },
    };
  },
  methods: {
    updateFilters() {
      this.$emit("update:model-value", this.filters);
    },
    handleCategoryChange(val) {
      this.filters.categories = val ? [val] : [];
      this.$emit("update:model-value", this.filters);
    },
    handleImgFilterChange(val) {
      if (val?.id === "with") {
        this.filters.has_img = true;
      } else if (val?.id === "without") {
        this.filters.has_img = false;
      } else {
        this.filters.has_img = null;
      }
      this.$emit("update:model-value", this.filters);
    },
    handleFillFilterChange(val) {
      this.filters.fill_percent_gte = val?.gte ?? null;
      this.filters.fill_percent_lt = val?.lt ?? null;
      this.$emit("update:model-value", this.filters);
    },
    handleFlagFilterChange(field, val) {
      this.filters[field] = typeof val?.value === "boolean" ? val.value : null;
      this.$emit("update:model-value", this.filters);
    },
    flagFilterOption(options, value) {
      if (typeof value !== "boolean") return null;
      return options.find((o) => o.value === value) ?? null;
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
    flex-wrap wrap
    align-items center
    gap 8px
  }

  &__category-filter {
    min-width 250px
    max-width 300px
  }

  &__img-filter {
    min-width 180px
    max-width 220px
  }

  &__fill-filter {
    min-width 180px
    max-width 220px
  }

  &__flag-filter {
    min-width 180px
    max-width 220px
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
