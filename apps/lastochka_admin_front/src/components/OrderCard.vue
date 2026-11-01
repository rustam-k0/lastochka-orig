<template>
  <div class="order-card" :class="{ 'order-card--error': error }">
    <div class="order-card__header">
      <CheckboxComponent @update:model-value="updateModelValue" :model-value="modelValue"
        >К 812-863</CheckboxComponent
      >
      <span class="order-card__header-date">02.01.23 02:02</span>
    </div>
    <div class="order-card__status">
      <span class="status status--blue order-card__status-1">
        <IconComponent name="flashlight-fill" />
      </span>
      <span class="status status--green order-card__status-2"> Оплачен </span>
    </div>
    <div class="order-card__date">
      <span>16:00-17:00</span>
      <span> Сегодня</span>
    </div>
    <div class="order-card__time">
      <span>00:35:16</span>
      <IconComponent name="error-warning-fill" />
    </div>
    <div class="order-card__title">
      <span> Никифорова Е. </span>
      <div class="badge">Новый</div>
    </div>
    <div class="order-card__state">
      <div class="order-card__state-info">
        <IconComponent name="shopping-basket-2-line" />
        <span> 3 товара </span>
      </div>
      <div class="order-card__state-weight">2,1 кг</div>
    </div>
  </div>
</template>

<script>
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
export default {
  name: "OrderCard",
  emits: ["update:model-value"],

  props: {
    modelValue: {
      type: Boolean,
      default: false,
    },
    error: {
      type: Boolean,
      default: false,
    },
  },

  data() {
    return {
      active: false,
    };
  },

  methods: {
    updateModelValue($event) {
      this.$emit("update:model-value", $event);
    },
  },

  components: {
    IconComponent,
    CheckboxComponent,
  },
};
</script>

<style lang="stylus">
.order-card {
  display flex
  flex-direction column
  background var(--white)
  padding 16px
  border-radius 8px
  border 1px solid var(--white)
  transition .2s

  &--error {
    background #FFF9F9
    border 1px dashed var(--red)
  }

  &:hover:not(&--error) {
    border 1px solid #E5E8EA
  }

  &__header {
    display flex
    justify-content space-between
    margin-bottom: 16px;
  }

  &__header-date {
    font-weight: 400;
    font-size: 0.688em
    color var(--gray-2)
  }

  &__status {
    width 100%
    display flex
    gap 4px
    margin-bottom 16px
  }

  &__status-1 {
    width 100%
    max-width 28px
    height 28px
  }

  &__status-2 {
    width 100%
  }

  &__date {
    display flex
    justify-content center
    font-weight: 400;
    font-size: 0.75rem;
    gap 4px
    margin-bottom 8px
  }

  &__time {
    display flex
    justify-content center
    align-items center
    line-height 33px
    font-weight: 500;
    font-size: 1.5rem;
    margin-bottom: 16px;
    gap 4px

    .icon svg {
      width 20px
      height 20px

      path {
        fill var(--orange-2)
      }
    }
  }

  &__title {
    display flex
    justify-content center
    align-items center
    line-height 20px
    gap 4px
    //font-family: 'Montserrat';
    font-weight: 500;
    font-size: 0.875em
    margin-bottom: 8px;
  }

  &__state {
    display flex
    justify-content space-between
    align-items center
    padding 8px 16px
    background #F8F8F8
    border-radius 6px
    cursor: pointer;
    transition .2s

    &:active {
      transform scale(0.95)
    }
  }
  &__state-info {
    display flex
    align-items center
    gap 4px
    font-size 12px
    line-height 16px
    color #5D6368

    .icon svg {
      width 20px
      height 20px

      path {
        fill #5D6368
      }
    }
  }

  &__state-weight {
    font-weight 400
    font-size 12px
  }
}
</style>
