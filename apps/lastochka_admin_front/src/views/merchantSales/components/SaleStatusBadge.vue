<template>
  <span class="sale-status-badge" :class="`sale-status-badge--${normalizedStatus}`">
    {{ label }}
  </span>
</template>

<script>
export default {
  name: "SaleStatusBadge",
  props: {
    status: {
      type: String,
      required: true,
    },
  },
  computed: {
    normalizedStatus() {
      return (this.status || "").toLowerCase();
    },
    label() {
      const labels = {
        upcoming: "Предстоящая",
        active: "Активна",
        expired: "Завершена",
        inactive: "Неактивна",
      };
      return labels[this.normalizedStatus] || this.status;
    },
  },
};
</script>

<style lang="stylus">
.sale-status-badge {
  display inline-flex
  align-items center
  padding 4px 12px
  border-radius 100px
  font-size 0.75rem
  font-weight 500
  line-height 1.4
  
  &--upcoming {
    background rgba(59, 130, 246, 0.1)
    color #3B82F6
  }
  
  &--active {
    background rgba(34, 197, 94, 0.1)
    color #22C55E
  }
  
  &--expired {
    background rgba(156, 163, 175, 0.1)
    color #9CA3AF
  }
  
  &--inactive {
    background rgba(239, 68, 68, 0.1)
    color #EF4444
  }
}
</style>
