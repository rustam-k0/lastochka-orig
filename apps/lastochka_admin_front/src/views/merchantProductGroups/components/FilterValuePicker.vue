<template>
  <div class="filter-value-picker" v-if="moduleEnabled && filters.length">
    <h3 class="filter-value-picker__title">Фильтры комплексных решений</h3>
    <div v-for="filter in filters" :key="filter.id" class="filter-value-picker__group">
      <label class="filter-value-picker__group-label">{{ filter.name }}</label>
      <div class="filter-value-picker__values">
        <label
          v-for="val in filter.values"
          :key="val.id"
          class="filter-value-picker__checkbox"
        >
          <input
            type="checkbox"
            :value="val.id"
            v-model="selectedIds"
            @change="emitUpdate"
          />
          <span class="filter-value-picker__checkbox-mark"></span>
          <span class="filter-value-picker__checkbox-text">{{ val.value }}</span>
        </label>
      </div>
    </div>
  </div>
</template>

<script>
import PRODUCT_GROUP_FILTERS from "@/graphql/productGroupFilters/product_group_filters.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

export default {
  name: "FilterValuePicker",
  inject: ["apollo"],
  props: {
    modelValue: {
      type: Array,
      default: () => [],
    },
  },
  emits: ["update:modelValue"],
  data() {
    return {
      filters: [],
      selectedIds: [],
    };
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(val) {
        this.selectedIds = val ? [...val] : [];
      },
    },
  },
  computed: {
    moduleEnabled() {
      return useModuleConfigStore().isModuleEnabled("product_group_filters");
    },
  },
  mounted() {
    if (this.moduleEnabled) {
      this.fetchFilters();
    }
  },
  methods: {
    fetchFilters() {
      this.apollo()
        .admin.query({
          query: PRODUCT_GROUP_FILTERS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const allFilters = data.productGroupFilters || [];
          this.filters = allFilters
            .filter((f) => f.is_active && f.values && f.values.length)
            .map((f) => ({
              ...f,
              values: f.values.filter((v) => v.is_active),
            }))
            .filter((f) => f.values.length > 0);
        })
        .catch((error) => {
          console.error("Error fetching product group filters:", error);
        });
    },
    emitUpdate() {
      this.$emit("update:modelValue", [...this.selectedIds]);
    },
  },
};
</script>

<style lang="stylus">
.filter-value-picker {
  &__title {
    font-size 1rem
    font-weight 600
    color var(--dark)
    margin-bottom 16px
  }

  &__group {
    margin-bottom 16px

    &:last-child {
      margin-bottom 0
    }
  }

  &__group-label {
    display block
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    margin-bottom 8px
  }

  &__values {
    display flex
    flex-wrap wrap
    gap 12px
  }

  &__checkbox {
    display flex
    align-items center
    gap 8px
    cursor pointer

    input {
      display none
    }
  }

  &__checkbox-mark {
    width 18px
    height 18px
    border 2px solid var(--gray-dark)
    border-radius 4px
    position relative
    transition border-color 0.2s, background 0.2s
    flex-shrink 0

    input:checked + & {
      background var(--pink)
      border-color var(--pink)

      &::after {
        content ''
        position absolute
        top 2px
        left 5px
        width 5px
        height 9px
        border solid var(--white)
        border-width 0 2px 2px 0
        transform rotate(45deg)
      }
    }
  }

  &__checkbox-text {
    font-size 0.875rem
    color var(--dark)
  }
}
</style>
