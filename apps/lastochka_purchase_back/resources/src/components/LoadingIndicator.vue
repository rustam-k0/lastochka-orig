<template>
  <div class="loading-indicator">
    <span>{{ title }}</span>
    <div class="loading-indicator__icon">
      <IconComponent category="system" name="loader-2-line" />
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
/**
 * Текст "Подождите" с динамическими точками
 */
export default {
  name: "LoadingIndicator",
  props: {
    title: {
      type: String,
      default() {
        return "Подождите, идет загрузка";
      },
    },
  },
  data() {
    return {
      dots: 0,
      interval: null,
    };
  },
  created() {
    this.interval = setInterval(() => {
      if (this.dots >= 3) {
        this.dots = 0;
      } else {
        this.dots += 1;
      }
    }, 100);
  },
  components: {
    IconComponent,
  },
};
</script>

<style lang="stylus">
@keyframes rotating {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
.loading-indicator {
  display: inline-flex;
  align-items: center;
  position: relative;
  gap 6px

  span {
    line-height: 12px;
  }

  &__icon {
    width: 16px;
    height: 16px;
    animation: rotating 1s linear infinite;

    .icon {
      width 100%
      height 100%
    }
  }
}
</style>
