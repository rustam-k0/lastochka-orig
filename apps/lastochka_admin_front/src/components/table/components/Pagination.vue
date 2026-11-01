<template>
  <div class="pagination">
    <div v-if="page !== null && totalItems !== null" class="pagination__list">
      <button
        v-if="page !== 1"
        class="btn btn--white btn--pagination"
        type="button"
        @click="$emit('update:page', 1)"
      >
        <IconComponent name="arrow-left-s-line" />
      </button>
      <button
        v-for="(p, i) in currentFive"
        :key="i"
        :class="{
          // 'btn--white': page === p,
          'btn--pagination-active': page === p,
        }"
        class="btn btn--white btn--pagination"
        type="button"
        @click="$emit('update:page', p)"
      >
        {{ p }}
      </button>
      <button
        v-if="page !== total"
        class="btn btn--white btn--pagination"
        type="button"
        @click="$emit('update:page', total)"
      >
        <IconComponent name="arrow-right-s-line" />
      </button>
    </div>
    <SelectComponent
      v-if="!hidePageSize"
      v-model="count"
      :options="counts"
      @update:model-value="$emit('update:count', $event.id)"
      small
    />
  </div>
</template>

<script>
import SelectComponent from "@/components/inputs/select/input.vue";
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "PaginationComponent",
  emits: ["update:page", "update:count"],
  components: { IconComponent, SelectComponent },
  props: {
    hidePageSize: { type: Boolean, default: false },
    total: {
      type: Number,
      default: () => 0,
    },
    page: {
      type: Number,
      default: () => 1,
    },
    first: {
      type: Number,
      default: () => 10,
    },
    totalItems: Number,
  },
  mounted() {
    this.count = this.counts.find((c) => c.id === this.first);
  },
  data() {
    return {
      count: null,
      counts: [
        {
          id: 25,
          title: "25",
        },
        {
          id: 50,
          title: "50",
        },
        {
          id: 75,
          title: "75",
        },
        {
          id: 100,
          title: "100",
        },
      ],
    };
  },
  computed: {
    currentFive() {
      let startPage, endPage;
      let maxPages = 5;
      if (this.total <= maxPages) {
        // total pages less than max so show all pages
        startPage = 1;
        endPage = this.total;
      } else {
        // total pages more than max so calculate start and end pages
        let maxPagesBeforeCurrentPage = Math.floor(maxPages / 2);
        let maxPagesAfterCurrentPage = Math.ceil(maxPages / 2) - 1;
        if (this.page <= maxPagesBeforeCurrentPage) {
          // current page near the start
          startPage = 1;
          endPage = maxPages;
        } else if (this.page + maxPagesAfterCurrentPage >= this.total) {
          // current page near the end
          startPage = this.total - maxPages + 1;
          endPage = this.total;
        } else {
          // current page somewhere in the middle
          startPage = this.page - maxPagesBeforeCurrentPage;
          endPage = this.page + maxPagesAfterCurrentPage;
        }
      }
      return Array.from(Array(endPage + 1 - startPage).keys()).map(
        (i) => startPage + i
      );
    },
  },
};
</script>

<style lang="stylus">
.pagination {
  display inline-flex
  align-items flex-end
  justify-content center
  width 100%

  .select {
    width 100px
  }

  &__list {
    display inline-flex
    align-items center
    gap 4px

    .btn:not(:first-child):not(:last-child) {
      border-radius 0
    }

    .btn:first-child {
      border-top-right-radius 0
      border-bottom-right-radius 0
    }

    .btn:last-child {
      border-top-left-radius 0
      border-bottom-left-radius 0
    }
  }
}
</style>
