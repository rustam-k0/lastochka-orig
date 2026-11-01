<template>
  <div class="pagination__container">
    <ul v-if="page !== null && totalItems !== null && totalItems > first" class="pagination">
      <li v-if="page !== 1">
        <button class="pagination__btn" type="button" @click="$emit('paginate', 1)">
          <Icon category="system" name="arrow-left-s-line" />
        </button>
      </li>
      <li v-for="(p, i) in currentFive" :key="i">
        <button
          :class="{ 'pagination__btn--active': page === p }"
          class="pagination__btn"
          type="button"
          @click="$emit('paginate', p)"
        >
          {{ p }}
        </button>
      </li>
      <li v-if="page !== total">
        <button class="pagination__btn" type="button" @click="$emit('paginate', total)">
          <Icon category="system" name="arrow-right-s-line" />
        </button>
      </li>
    </ul>
    <SelectComponent
      v-model="count"
      :clearable="false"
      :options="counts"
      label-name="title"
      title=""
      @input="$emit('change', count)"
    />
  </div>
</template>

<script>
import Icon from "./IconComponent.vue";
import SelectComponent from "./inputs/select";

export default {
  name: "PaginationComponent",
  props: {
    page: Number,
    total: Number,
    totalItems: Number,
    first: Number,
    pagination: Boolean,
  },
  watch: {
    first: {
      immediate: true,
      handler(val) {
        this.count = this.counts.find((c) => c.id === val);
      },
    },
  },
  data() {
    return {
      count: null,
      counts: [
        {
          id: 5,
          title: "5",
        },
        {
          id: 10,
          title: "10",
        },
        {
          id: 20,
          title: "20",
        },
        {
          id: 30,
          title: "30",
        },
        {
          id: 40,
          title: "40",
        },
        {
          id: 50,
          title: "50",
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
      return Array.from(Array(endPage + 1 - startPage).keys()).map((i) => startPage + i);
    },
  },
  components: {
    SelectComponent,
    Icon,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.pagination {
  display flex
  justify-content center
  align-items center
  list-style none

  &__container {
    display flex
    align-items center
    justify-content space-between
    margin-top 24px

    .select {
      width: 65px;
      margin-left: auto;
    }
  }

  &__btn {
    background: $white;
    border-radius: 10px;
    width 44px
    height 44px
    display flex
    justify-content center
    align-items center
    border 1px solid transparent
    box-sizing border-box
    font-weight: normal;
    font-size: 0.8750em;
    line-height 20px;
    text-align: center;
    outline none
    color: $dark;
    cursor pointer

    &--active {
      border: 1px solid $gray1;
      color $dark
      font-weight 500
    }

    .icon {
      width 16px
      height 16px

      svg {
        width 100%
        height 100%
        svg($dark)
      }
    }
  }
}
</style>
