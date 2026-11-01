<template>
  <div v-if="modals.length" class="modal" ref="modal">
    <div class="modal__content">
      <button ref="background" class="modal__background" @click="closeLast"></button>
      <component
        :is="modals[modals.length - 1].component"
        v-bind="modals[modals.length - 1].options"
        @close="closeLast"
      ></component>
    </div>
  </div>
</template>

<script>
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "ModalsComponent",
  methods: {
    closeLast() {
      return useDefaultStore().modals.splice(useDefaultStore().modals.length - 1, 1);
    },
  },
  computed: {
    modals() {
      return useDefaultStore().modals;
    },
  },
  watch: {
    "modals.length"(val) {
      this.$nextTick(() => {
        if (this.$refs.background && this.$refs.modal) {
          this.$refs.background.style.height = `${this.$refs.modal.scrollHeight}px`;
        }
      });

      if (val > 0) {
        document.body.classList.add("no-overflow");
      } else {
        document.body.classList.remove("no-overflow");
      }
    },
    "$store.state._modals.length"(val) {
      if (val > 0) {
        document.body.classList.add("no-overflow");
      } else {
        document.body.classList.remove("no-overflow");
      }
    },
    "$route.name"() {
      useDefaultStore().modals = [];
    },
    "$route.params"() {
      useDefaultStore().modals = [];
    },
  },
};
</script>

<style lang="stylus">
.bounce-enter-active {
  animation: bounce-in .5 cubic-bezier(0.175, 0.885, 0.320, 1.275);
}

.bounce-enter {
  animation: bounce-in .5 reverse cubic-bezier(0.175, 0.885, 0.320, 1.275);
}

.modal {
  position fixed
  left 0
  top 0
  width 100%
  height 100%
  z-index 999
  background var(--black-o5)
  display flex
  padding-bottom 45px
  overflow auto
  justify-content center
  align-items center

  &__background {
    position absolute
    left 0
    top 0
    width 100%
    height 100%
    background none
    box-shadow none
    border none
    outline none
    padding 0
    margin 0
  }

  &__content {
    width 100%
    height 100%
    position relative
    display none
    align-items flex-start
    justify-content center
    //overflow-y auto

    &:last-child {
      display flex
      margin-top 15px
      //align-items: center;
    }
  }
}

.mod {
  margin auto
  margin-top 45px
  background var(--white)
  box-shadow 0 5px 10px rgba(0, 0, 0, 0.05)
  border-radius 10px
  z-index 1
  position relative

  &__header {
    display flex
    align-items center
    padding 30px
    gap 30px
    border-bottom 1px solid var(--gray-dark)
    justify-content space-between
  }

  &__title {
    font-weight: 700;
    font-size: 1.5rem;
    line-height: 29px;
    color: var(--dark);
  }

  &__close {
    width 30px
    height 30px
    background none
    padding 0
    border none
    display inline-flex
    align-items center
    justify-content center

    .icon {
      width 100%
      height 100%

      svg path {
        fill var(--dark-light)
      }
    }
  }

  &__body {
    display grid
    padding 30px
    grid-gap 30px
    //justify-content space-between
  }

  &__section {
    display grid
    width 100%
    grid-gap 20px
  }

  &__section-title {
    font-weight: 700;
    font-size: 1.25rem;
    line-height: 28px;
    color: var(--dark-light);
  }

  &__section-body {
    display grid
    grid-gap 20px
  }

  &__footer {
    display flex
    align-items center
    padding 30px
    gap 30px
    justify-content space-between
  }

  &__footer-box {
    display inline-flex
    align-items center
    gap 15px
  }
}
</style>
