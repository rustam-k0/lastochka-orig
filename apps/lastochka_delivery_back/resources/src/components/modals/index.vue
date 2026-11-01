<template>
  <transition-group v-show="$store.state._modals.length" class="modal" name="bounce" tag="div">
    <div v-for="(modal, index) in modals" :key="JSON.stringify(modal) + index" class="modal__content">
      <div ref="background" class="modal__background" @click="close(index)"></div>
      <div
        ref="modal"
        :class="{ 'modal__body--bottom': modal.alignBottom, 'modal__body--top': modal.alignTop }"
        class="modal__body"
      >
        <component
          :is="modal.component"
          :key="index"
          v-bind="modal.options"
          @close="close(index)"
        ></component>
      </div>
    </div>
  </transition-group>
</template>

<script>
export default {
  name: "ModalComponent",
  methods: {
    close(i) {
      return this.$store.state._modals.splice(i, 1);
    },
  },
  mounted() {
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        this.close(this.$store.state._modals.length - 1);
      }
    });
  },
  beforeDestroy() {
    document.removeEventListener("keydown", () => {});
  },
  computed: {
    modals() {
      return this.$store.state._modals;
    },
  },
  updated() {
    if (this.$refs.modal && this.$refs.modal.length) {
      setTimeout(() => {
        this.$refs.modal.forEach((m, i) => {
          const bounds = m.getBoundingClientRect();
          this.$refs.background[i].style = `width:${bounds.width}px`;
        });
      }, 300);
    }
  },
  watch: {
    "$store.state._modals.length"(val) {
      if (val > 0) {
        document.body.classList.add("no-overflow");
      } else {
        document.body.classList.remove("no-overflow");
      }
    },
    "$route.name"() {
      this.$store.state._modals = [];
    },
    "$route.params"() {
      this.$store.state._modals = [];
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
@keyframes bounce-in {
  0% {
    transform: scale(0.98);
  }
  50% {
    transform: scale(1.02);
  }
  100% {
    transform: scale(1);
  }
}

.bounce-enter-active {
  animation: bounce-in .3 cubic-bezier(0.175, 0.885, 0.320, 1.275);
}

.bounce-enter {
  animation: bounce-in .3 reverse cubic-bezier(0.175, 0.885, 0.320, 1.275);
}

.modal {
  fixed left top
  width 100%
  height 100%
  z-index 999
  overflow hidden
  background: rgba($black, .2);

  &__content {
    width 100%
    height 100%
    position relative
    display none
    align-items flex-start
    justify-content center
    overflow-y auto

    &:last-child {
      display flex
    }
  }

  &__background {
    fixed left top
    height 100%
    z-index 0
    cursor pointer
  }

  &__body {
    display flex
    justify-content: center;
    width 100%
    margin: 80px 15px;
    +below(590px) {
      margin 0 15px
    }

    &--top {
      margin-top: 0;
      margin-bottom: auto;
    }

    &--bottom {
      margin-top: auto;
      margin-bottom: 0;
    }
  }
}

.modal-body {
  max-width: 330px;
  width 100%
  z-index 2
  background: $white;
  box-shadow: 0 16px 24px rgba($black, .06), 0px 2px 6px rgba($black, .04), 0px 0px 1px rgba($black, .04);
  border-radius: 10px;
  display grid
  grid-gap 15px
  padding-bottom 15px

  &__header {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 0 15px;
    padding-top 15px
  }

  &__footer,
  &__buttons {
    display flex
    gap 5px
    padding 0 15px
  }

  &__title {
    font-size 1.375em
    font-weight: 500;
    margin: 0;
  }

  &__close {
    background none
    padding 0
    border 0
    cursor pointer
    absolute right 15px top 15px

    .icon {
      width 24px
      height 24px

      svg {
        width 100%
        height 100%

        path {
          fill $dark
        }
      }
    }
  }

  &__content {
    display flex
    flex-direction column
    align-items flex-start
    justify-content flex-start
    width 100%
    padding 0 15px
    gap 10px
  }
}
</style>
