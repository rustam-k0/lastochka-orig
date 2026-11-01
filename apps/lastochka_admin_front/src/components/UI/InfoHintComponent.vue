<template>
  <span
    ref="root"
    class="info-hint"
    :class="{ 'info-hint--open': visible }"
    @mouseenter="onEnter"
    @mouseleave="onLeave"
  >
    <button
      type="button"
      class="info-hint__btn"
      :aria-label="ariaLabel"
      :aria-expanded="visible ? 'true' : 'false'"
      @click.stop="toggle"
      @keydown.esc="hide"
    >
      <IconComponent :name="icon" />
    </button>
    <Teleport to="body" :disabled="!teleport">
      <span
        v-if="visible"
        ref="bubble"
        class="info-hint__bubble"
        :class="[`info-hint__bubble--${position}`, { 'info-hint__bubble--fixed': teleport }]"
        :style="teleport ? fixedStyle : null"
        role="tooltip"
        @mouseenter="teleport && onBubbleEnter()"
        @mouseleave="teleport && onLeave()"
      >
        <slot>{{ text }}</slot>
      </span>
    </Teleport>
  </span>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

/**
 * Иконка-подсказка «i» с всплывающим текстом.
 *
 * Нативный `title` не годится: браузер показывает его через 1–2 секунды и
 * полностью игнорирует на тач-устройствах, из-за чего подсказки выглядят
 * декоративными значками без смысла (MID-211). Здесь подсказка открывается
 * сразу по наведению и по тапу/клику, закрывается кликом вне и по Esc.
 */
export default {
  name: "InfoHintComponent",
  components: { IconComponent },
  props: {
    text: {
      type: String,
      default: "",
    },
    icon: {
      type: String,
      default: "information-line",
    },
    // right — подсказка прижата правым краем (для иконок в правом углу блока)
    position: {
      type: String,
      default: "right",
      validator: (v) => ["left", "right", "center"].includes(v),
    },
    ariaLabel: {
      type: String,
      default: "Подсказка",
    },
    // Подсказка внутри контейнера с overflow (ячейка таблицы): пузырь выводится
    // в body с фиксированным позиционированием по координатам иконки, иначе
    // контейнер его обрезает (MID-308, лесенка цен в карточке заказа).
    teleport: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      visible: false,
      pinned: false,
      fixedStyle: null,
      leaveTimer: null,
    };
  },
  beforeUnmount() {
    document.removeEventListener("click", this.onOutsideClick);
    document.removeEventListener("keydown", this.onEsc);
    this.unwatchViewport();
    clearTimeout(this.leaveTimer);
  },
  methods: {
    onEnter() {
      clearTimeout(this.leaveTimer);
      this.show();
    },
    onBubbleEnter() {
      // Пузырь в body лежит вне корня: переход курсора с иконки на пузырь
      // даёт mouseleave корня, закрытие отложено и здесь отменяется.
      clearTimeout(this.leaveTimer);
    },
    onLeave() {
      // Открытую кликом подсказку уводом курсора не закрываем: на тач-устройствах
      // mouseleave не приходит вовсе, закрытие там — по тапу вне.
      if (this.pinned) return;
      if (!this.teleport) {
        this.visible = false;
        return;
      }
      clearTimeout(this.leaveTimer);
      this.leaveTimer = setTimeout(() => {
        if (!this.pinned) this.close();
      }, 120);
    },
    show() {
      this.visible = true;
      if (this.teleport) {
        this.place();
        this.watchViewport();
      }
    },
    close() {
      this.visible = false;
      this.unwatchViewport();
    },
    toggle() {
      this.pinned = !this.pinned;
      if (this.pinned) {
        this.show();
        document.addEventListener("click", this.onOutsideClick);
        document.addEventListener("keydown", this.onEsc);
      } else {
        this.hide();
      }
    },
    hide() {
      this.pinned = false;
      this.close();
      document.removeEventListener("click", this.onOutsideClick);
      document.removeEventListener("keydown", this.onEsc);
    },
    onOutsideClick(event) {
      const inRoot = this.$refs.root && this.$refs.root.contains(event.target);
      const inBubble = this.$refs.bubble && this.$refs.bubble.contains(event.target);
      if (!inRoot && !inBubble) this.hide();
    },
    // Координаты пузыря от иконки: снизу, у нижнего края окна переворачивается
    // наверх; по горизонтали по тому же правилу, что position.
    place() {
      const btn = this.$refs.root;
      if (!btn) return;
      const rect = btn.getBoundingClientRect();
      const gap = 8;
      const style = { top: `${rect.bottom + gap}px`, right: "auto" };
      if (this.position === "left") {
        style.left = `${rect.left}px`;
        style.transform = "none";
      } else if (this.position === "center") {
        style.left = `${rect.left + rect.width / 2}px`;
        style.transform = "translateX(-50%)";
      } else {
        style.left = `${rect.right}px`;
        style.transform = "translateX(-100%)";
      }
      this.fixedStyle = style;
      this.$nextTick(() => {
        const bubble = this.$refs.bubble;
        if (!bubble) return;
        const height = bubble.offsetHeight;
        if (rect.bottom + gap + height > window.innerHeight && rect.top - gap - height > 0) {
          this.fixedStyle = { ...this.fixedStyle, top: `${rect.top - gap - height}px` };
        }
      });
    },
    watchViewport() {
      window.addEventListener("scroll", this.place, true);
      window.addEventListener("resize", this.place);
    },
    unwatchViewport() {
      window.removeEventListener("scroll", this.place, true);
      window.removeEventListener("resize", this.place);
    },
    onEsc(event) {
      if (event.key === "Escape") this.hide();
    },
  },
};
</script>

<style lang="stylus">
.info-hint {
  position relative
  display inline-flex
  align-items center

  &__btn {
    display inline-flex
    align-items center
    justify-content center
    padding 0
    border none
    background none
    color var(--dark-light)
    cursor pointer
    transition color 0.2s

    .icon svg {
      width 18px
      height 18px
    }

    &:hover
    &:focus-visible {
      color var(--green)
    }
  }

  &__bubble {
    position absolute
    top calc(100% + 8px)
    z-index 20
    width max-content
    max-width 320px
    padding 10px 12px
    background var(--dark, #2E3141)
    border-radius 8px
    color var(--white)
    font-size 0.8125rem
    font-weight 400
    line-height 140%
    white-space normal
    box-shadow 0 4px 16px rgba(0, 0, 0, 0.16)

    &--right {
      right 0
    }

    &--left {
      left 0
    }

    &--center {
      left 50%
      transform translateX(-50%)
    }

    &--fixed {
      position fixed
      z-index 1000
    }
  }
}

@media (max-width 480px) {
  .info-hint__bubble {
    max-width 220px
  }
}
</style>
