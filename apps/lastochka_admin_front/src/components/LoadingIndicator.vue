<template>
  <div class="loading-indicator">
    <svg
      xmlns="http://www.w3.org/2000/svg"
      style="background: rgba(224, 102, 102, 0); display: block"
      width="250px"
      height="250px"
      viewBox="0 0 100 100"
      preserveAspectRatio="xMidYMid"
    >
      <circle
        cx="50"
        cy="50"
        fill="none"
        stroke-width="8"
        r="40"
        stroke-dasharray="188.49555921538757 64.83185307179586"
      >
        <animateTransform
          attributeName="transform"
          type="rotate"
          repeatCount="indefinite"
          dur="0.6666666666666666s"
          values="0 50 50;360 50 50"
          keyTimes="0;1"
        ></animateTransform>
      </circle>
    </svg>
    <span>{{ title }}</span>
  </div>
</template>

<script>
export default {
  name: "LoadingIndicator",
  props: {
    title: {
      type: String,
      default: "Подождите, идет загрузка",
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
};
</script>

<style lang="stylus">
.loading-indicator {
  display inline-flex
  align-items center
  position relative
  gap 10px

  & svg {
    width 16px
    height 16px

    circle {
      stroke: var(--dark)
    }
  }
}
</style>
