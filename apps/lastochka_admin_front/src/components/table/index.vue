<template>
  <div
    class="table"
    :class="{
      'table--wide': settings.asideFolded,
      'table--pagination':
        pagination && paginationVisible && total > paginationThreshold,
      'table--with-header': $slots.header,
    }"
    v-if="columns && columns.length"
  >
    <div v-if="$slots.header" class="table__header">
      <slot name="header"></slot>
    </div>
    <div class="table__container">
      <div
        class="table__row table__row--header"
        :style="{
          'grid-template-columns': columnWidth(),
        }"
      >
        <div class="table__col table__col--check" v-if="selectable">
          <CheckboxComponent
            small
            :modelValue="selected.length === rows.length"
            @update:model-value="selectAll"
            :indeterminate="
              selected.length > 0 && selected.length !== rows.length
            "
          />
        </div>
        <template v-for="(col, i) in columnsWithOrdering" :key="i">
          <button
            v-if="col.sortable"
            @click.exact.prevent="addToSort(col.field)"
            @click.shift.prevent="addToSort(col.field, true)"
            type="button"
            class="table__col"
          >
            <span v-if="col.title && col.show">{{ col.title }}</span>
            <span
              type="button"
              class="table__sort"
              :class="{
                'table__sort--asc': col.sort && col.sort === 'ASC',
                'table__sort--desc': col.sort && col.sort === 'DESC',
              }"
            >
              <IconComponent name="sort-desc" />
            </span>
          </button>
          <div v-else class="table__col">
            <span v-if="col.title">{{ col.title }}</span>
          </div>
        </template>
      </div>
      <template v-if="rows.length">
        <div
          class="table__row"
          :style="{
            'grid-template-columns': columnWidth(),
          }"
          v-for="(row, rI) in rows"
          :key="rI"
        >
          <div class="table__col table__col--check" v-if="selectable">
            <CheckboxComponent
              :modelValue="selectedIndex(row) >= 0"
              @update:model-value="toggleSelected(row)"
            />
          </div>
          <div class="table__col" v-for="(col, cI) in columns" :key="cI">
            <slot
              name="col"
              :col="col"
              :row="row"
              :colIndex="cI"
              :rowIndex="rI"
            >
            </slot>
          </div>
        </div>
      </template>
      <div class="table__loading" v-else-if="loading">
        <IconComponent name="loading" category="default" />
        <span>Загрузка</span>
      </div>
      <div class="table__empty" v-else>{{ emptyMessage }}</div>
    </div>
    <div
      class="table__pagination"
      :class="{ 'table__pagination--active': paginationVisible }"
      v-if="pagination && total > paginationThreshold"
    >
      <button
        type="button"
        class="table__pagination-toggle"
        @click.prevent="paginationVisible = !paginationVisible"
      >
        <!--        <IconComponent name="arrow-up-2" />-->
      </button>
      <PaginationComponent
        :first="first"
        :page="page"
        :total="Math.ceil(total / first)"
        :totalItems="total"
        :hidePageSize="hidePageSize"
        @update:count="$emit('update:count', $event)"
        @update:page="$emit('update:page', $event)"
      />
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import { useSettingsStore } from "@/stores/settings.js";
import { useDefaultStore } from "@/stores/default";
import PaginationComponent from "@/components/table/components/Pagination.vue";

export default {
  name: "TableComponent",
  emits: ["update:ordering", "update:selected", "update:page", "update:count"],

  mounted() {
    // console.log(this.$slots.header);
  },
  props: {
    rows: Array,
    columns: Array,
    // default: страницы без сортировки не передают проп вовсе (quarantine)
    ordering: {
      type: Array,
      default: () => [],
    },
    selected: Array,
    selectBy: {
      type: String,
      default: "id",
    },
    emptyMessage: {
      type: String,
      default: "Данных нет",
    },
    loading: Boolean,
    selectable: Boolean,
    pagination: Boolean,
    hidePageSize: Boolean,
    page: Number,
    total: Number,
    first: Number,
    paginationThreshold: {
      type: Number,
      default: 20,
    },
  },

  data: () => ({
    paginationVisible: true,
  }),
  computed: {
    settings() {
      return useSettingsStore().settings;
    },
    types() {
      return useDefaultStore().types;
    },
    columnsWithOrdering() {
      return this.columns.map((c) => {
        let order = (this.ordering || []).find((o) => o.orderColumn === c.field);
        if (order) {
          c.sort = order.orderBy;
        } else {
          c.sort = undefined;
        }
        return c;
      });
    },
  },
  methods: {
    columnWidth() {
      const width = this.columns
        .map((c) => (c.width ? c.width : "minmax(100px, 1fr)"))
        .join(" ");
      if (this.selectable) {
        return "50px " + width;
      }
      return width;
    },
    selectAll(e) {
      if (e) {
        this.$emit("update:selected", this.rows);
      } else {
        this.$emit("update:selected", []);
      }
    },
    selectedIndex(row) {
      return this.selected.findIndex(
        (s) => s[this.selectBy] === row[this.selectBy]
      );
    },
    toggleSelected(row) {
      let array = this.selected;
      let selectedIndex = this.selectedIndex(row);
      if (selectedIndex >= 0) {
        array.splice(selectedIndex, 1);
      } else {
        array.push(row);
      }
      this.$emit("update:selected", array);
    },
    addToSort(field, multiple) {
      let instanceIndex = null;
      let instance = (this.ordering || []).find((s, index) => {
        if (s.orderColumn === field) {
          instanceIndex = index;
          return true;
        }
        return false;
      });
      let state = "DESC";
      if (instance) {
        if (instance.orderBy.toLowerCase() === "desc") {
          state = "ASC";
        }
        if (instance.orderBy.toLowerCase() === "asc") {
          state = false;
        }
      }
      let result = [];
      if (multiple) {
        let result = this.ordering;
        if (instance) {
          result[instanceIndex].orderBy = state;
        } else {
          result.push({
            orderColumn: field,
            orderBy: state,
          });
        }
        this.$emit("update:ordering", result);
      } else {
        if (state) {
          result = [
            {
              orderColumn: field,
              orderBy: state,
            },
          ];
        }
        this.$emit("update:ordering", result);
      }
    },
  },
  components: { PaginationComponent, CheckboxComponent, IconComponent },
};
</script>

<style lang="stylus">
.table {
  width 100%
  height 100%
  max-width calc(100vw - 270px)
  max-height calc(100vh - 190px)
  position relative
  transition max-width .2s

  &--wide {
    max-width calc(100vw - 110px)
  }

  &--with-header {

    & ^[0]__container {
      border-top-left-radius 0
      border-top-right-radius 0
      border-top none
    }
  }

  &--pagination &__container {
    height calc(100% - 100px) // высота пагинации
  }

  &__container {
    overflow auto
    max-height: 100%;
    scrollbar-width thin
    border 1px solid var(--border-color)
    scrollbar-color rgba(0, 0, 0, 0.3) transparent
    //border-top none
    //border-bottom-left-radius 10px
    //border-bottom-right-radius 10px
    background var(--white)
    border-radius 10px

    &::-webkit-scrollbar {
      width 8px
      height 8px
    }

    &::-webkit-scrollbar-track {
      background transparent
    }

    &::-webkit-scrollbar-thumb {
      background-clip: padding-box;
      background-color rgba(0, 0, 0, 0.3)
      border-radius 3px
      border none
    }
  }

  &__header {
    background var(--gray-3)
    border 1px solid var(--border-color)
    border-top-left-radius 10px
    border-top-right-radius 10px
  }

  &__row {
    display grid

    &:not(:last-child) ^[0]__col {
      border-bottom: 1px solid var(--border-color);
    }

    &--header {
      position sticky
      top 0
      z-index: 4;

      & ^[0]__col {
        position sticky
        top 0
        padding 13.5px 16px
      }

      // Ячейки шапки все sticky: без z-index чекбокс при горизонтальной
      // прокрутке уходил под соседние ячейки (MID-343)
      & ^[0]__col--check {
        z-index 1
      }
    }
  }

  &__col {
    background: var(--white);
    padding 13px 15px
    font-weight: 500;
    font-size: 0.813rem
    line-height: 24px;
    color: var(--dark);
    display flex
    gap 4px
    align-items center
    border none
    word-break break-word

    &:not(:last-child) {
      //border-right: 1px solid var(--gray-dark);
    }

    &--check {
      position sticky
      left 0

    }

    &-status {
      padding 4px 10px
      border-radius 5px
      background var(--dark)
      color var(--white)

      &--waiting {
        background var(--dark)
      }

      &--confirmed {
        background var(--blue)
      }

      &--came {
        background var(--green)
      }

      &--not-came {
        background var(--red)
      }
    }
  }

  &__loading,
  &__empty {
    background: var(--white);
    padding 13px 15px
    font-weight: 700;
    font-size: 0.875rem;
    line-height: 24px;
    color: var(--dark);
    display flex
    justify-content space-between
    align-items center
  }

  &__loading {
    justify-content flex-start
    gap 4px

    .icon {
      width 24px
      height 24px

      svg path {
        fill var(--dark)
      }
    }
  }

  &__sort {
    background none
    border none
    padding 0
    display inline-flex

    .icon {
      width 12px
      height 12px

      svg path {
        fill var(--dark-light)
        transition .2s
      }
    }

    &--desc .icon svg path.__up__ {
      fill var(--black)
    }

    &--asc .icon svg path.__down__ {
      fill var(--black)
    }
  }

  &__pagination {
    position sticky
    left 0
    bottom 0
    width 100%
    background var(--gray-3);
    border-top-left-radius 5px
    border-top-right-radius 5px
    padding 0 0 0 15px

    .pagination {
      display none
    }

    &--active .pagination {
      height 50px
      display inline-flex
    }

    &-toggle {
      position absolute
      top 0
      left 0
      right 0
      margin auto
      transform translateY(-100%)
      background var(--white)
      border-top-left-radius 5px
      border-top-right-radius 5px
      border none
      box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
      clip-path inset(23px 60px 30px 60px);
      z-index 1
      width 40px

      .icon {
        width 18px
        height 18px
      }
    }

    &--active &-toggle .icon {
      transform rotate(180deg)
    }
  }
}
</style>
