<template>
  <span class="promocode-status-badge" :class="`promocode-status-badge--${normalizedStatus}`">
    {{ label }}
  </span>
</template>

<script>
export default {
  name: "PromocodeStatusBadge",
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
        active: "Активен",
        scheduled: "Запланирован",
        expired: "Истёк",
        inactive: "Неактивен",
      };
      return labels[this.normalizedStatus] || this.status;
    },
  },
};
</script>

<style lang="stylus">
.promocode-status-badge {
  display inline-flex
  align-items center
  padding 4px 12px
  border-radius 100px
  font-size 0.75rem
  font-weight 500
  line-height 1.4
  white-space nowrap
  
  &--active {
    background rgba(34, 197, 94, 0.1)
    color #22C55E
  }
  
  &--scheduled {
    background rgba(245, 158, 11, 0.1)
    color #F59E0B
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
