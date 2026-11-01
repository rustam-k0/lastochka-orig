<template>
  <div class="modal-body alert-modal">
    <div
      class="alert-modal__icon"
      :class="{
        'alert-modal__icon--success': type === 'success',
        'alert-modal__icon--error': type !== 'success',
      }"
    >
      <IconComponent name="checkbox-circle-line" v-if="type === 'success'" category="system" class="icon" />
      <IconComponent name="error-warning-line" v-else category="system" class="icon" />
    </div>
    <div class="alert-modal__title">{{ title }}</div>
    <div class="alert-modal__subtitle">{{ subtitle }}</div>
    <button class="btn btn--main btn--small" type="button" @click="handleClick">{{ buttonTitle }}</button>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "AlertModal",
  props: {
    type: {
      type: String,
      default() {
        return "success";
      },
    },
    title: {
      type: String,
      default() {
        return "";
      },
    },
    subtitle: {
      type: String,
      default() {
        return "";
      },
    },
    buttonTitle: {
      type: String,
      default() {
        return "Закрыть";
      },
    },
    callback: {
      type: Function,
    },
  },
  methods: {
    handleClick() {
      this.callback();
      this.$emit("close");
    },
  },
  components: { IconComponent },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.alert-modal {
  display: flex;
  justify-content: center;
  align-items: center;
  text-align: center;
  flex-direction: column;

  &__icon {
    display flex
    flex-shrink 0
    justify-content: center;
    margin-top: 20px;
    margin-bottom: -10px;

    &--error .icon svg path {
      fill $red
    }

    &--success .icon svg path {
      fill $green
    }

    .icon {
      width 80px
      height 80px

      svg {
        width 100%
        height 100%
      }
    }
  }

  &__title {
    font-weight: bold;
    font-size: 2.25em;
    line-height: 44px;
    color: $black;
  }

  &__subtitle {
    font-weight: normal;
    font-size: 1em;
    line-height: 140%;
    color: $black;
  }

  .btn {
    padding-left 30px
    padding-right 30px
  }
}
</style>
