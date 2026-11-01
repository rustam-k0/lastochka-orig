<template>
  <div
    v-click-outside="blurList"
    :class="{
      'date-select--filled': singleSelected,
      'date-select--input': search.length || focused,
      'date-select--drop': opened,
      'date-select--loading': loading,
      'date-select--drop-top': listTop,
    }"
    class="date-select"
  >
    <div class="date-select__container">
      <input
        v-show="!loading"
        ref="input"
        v-model="search"
        :placeholder="title"
        class="date-select__field"
        readonly
        @blur="blur"
        @focus="focus"
      />
      <span v-show="!loading" class="date-select__empty" @click="focus">
        {{ formatDate(singleSelected) || emptyPlaceholder }}
      </span>
    </div>
    <button v-if="singleSelected" v-show="!loading" class="date-select__clear" type="button" @click="clean">
      <Icon category="system" name="close-line" />
    </button>
    <button v-show="!loading" class="date-select__action" tabindex="-1" type="button" @click="focus">
      <Icon category="business" name="calendar-line" />
    </button>
    <div v-show="!loading" class="date-select__drop">
      <DatePicker
        v-if="opened"
        v-model="singleSelected"
        :first-day-of-week="2"
        :is-range="range"
        :mode="mode"
        locale="ru"
        title-position="left"
        trim-weeks
        @input="$emit('input', $event)"
      />
    </div>
  </div>
</template>

<script>
import ClickOutside from "vue-click-outside";
import DatePicker from "v-calendar/lib/components/date-picker.umd";
import moment from "moment";
import Icon from "../IconComponent.vue";

export default {
  name: "DateComponent",
  props: {
    title: {
      type: String,
      required: true,
    },
    mode: {
      type: String,
      default() {
        return "date";
      },
    },
    emptyPlaceholder: {
      type: String,
      default() {
        return "Все";
      },
    },
    range: Boolean,
    loading: Boolean,
    value: [Object, Date],
  },
  data() {
    return {
      singleSelected: null,
      search: "",
      opened: false,
      listTop: false,
      scrollTimeout: undefined,
      focused: false,
    };
  },
  watch: {
    value() {
      this.singleSelected = this.value;
    },
    opened() {
      if (this.opened) {
        this.$nextTick(() => {
          this.calculateListPosition();
        });
      }
    },
  },
  mounted() {
    const placeholder = this.$refs.input.getAttribute("placeholder");
    this.$refs.input.setAttribute("size", placeholder.length - 2 + "");

    window.addEventListener("scroll", () => {
      if (this.opened) {
        clearTimeout(this.scrollTimeout);
        this.scrollTimeout = setTimeout(() => {
          this.$nextTick(() => {
            this.calculateListPosition();
          });
        }, 200);
      }
    });
  },
  beforeDestroy() {
    window.removeEventListener("scroll", () => {});
  },
  methods: {
    formatDate(date) {
      const format = "DD.MM.YYYY";
      if (date) {
        if (date instanceof Date) {
          return moment(date).format(format);
        }
        return moment(date.start).format(format) + " - " + moment(date.end).format(format);
      }
      return date;
    },
    calculateListPosition() {
      if (this.$refs.list) {
        const height = this.$refs.list.$el.clientHeight;
        const top = this.$refs.list.$el.getBoundingClientRect().top;
        if (top + height > window.innerHeight) {
          this.listTop = true;
          return;
        }
        this.listTop = false;
      }
    },
    clean() {
      this.singleSelected = null;
      this.$emit("input", null);
    },
    /**
     * Обработка события выбора
     */
    select() {},
    /**
     * Фокус на input
     */
    focus() {
      this.$refs.input.focus();
      this.focused = true;
      this.opened = true;
    },
    /**
     * Расфокус на input
     */
    blur() {
      this.focused = false;
    },
    /**
     * Убрать список
     */
    blurList() {
      this.opened = false;
    },
  },
  components: {
    Icon,
    DatePicker,
  },
  directives: {
    ClickOutside,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
@import "~@/styles/elements/input.styl"
.date-select {
  @extends .input
  transition background-color .2s
  display flex
  align-items center

  &--loading {
    background-color rgba($main_color, .1);
    background-size: 31px 31px;
    background-image: linear-gradient(-45deg, rgba(0, 0, 0, .1) 25%, rgba(0, 0, 0, 0) 0, rgba(0, 0, 0, 0) 50%, rgba(0, 0, 0, .1) 0, rgba(0, 0, 0, .1) 75%, rgba(0, 0, 0, 0) 0, rgba(0, 0, 0, 0));
    animation: stripped 2s linear 0s infinite normal none running;
  }

  &__drop {
    absolute bottom left
    max-width calc(100% + 2px)
    transform translateY(calc(100% - 1px))
    z-index 10
    box-shadow: 0 0 8px rgba($black, 0.1);
    border-radius 0 0 10px 10px

    .vc-container {
      font-family $font_regular
      border-radius 0 0 10px 10px
      left -1px
      border-color $gray1
      border-top-color transparent
      --font-normal: 300;
      --font-medium: 400;
      --font-semibold: 500;
      --font-bold: 600;
      --text-xs: 12px;
      --text-sm: 0.8750em;
      --text-base: 1em;
      --text-lg: 18px;
      --leading-snug: 1.375;
      --rounded: 4px;
      --rounded-lg: 4px;
      --rounded-full: 100%;
      --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
      --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      --shadow-inner: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
      --slide-translate: 22px;
      --slide-duration: 0.2s;
      --slide-timing: ease;
      --day-content-transition-time: 0.2s ease-in;
      --weeknumber-offset: -34px;

      .vc-title {
        text-transform: capitalize;
      }

      &.vc-blue {
        --accent-500: $main_color;
      }
    }
  }

  &--filled {
    background $white
  }

  &__container {
    width: 100%;
    height: 100%;
  }

  &__empty {
    font-size: 1em;
    line-height: 19px;
    color: $dark;
    height 100%
    padding 20px 12px 5px
    display flex
    align-items center
    min-width 95px
    width: 100%;
    position: absolute;
    left: 0;
    bottom: 0;
  }

  &__action {
    padding 10px 12px
  }

  &--drop {
    background $white
    border-radius 10px 10px 0 0
    border-bottom-color transparent

    & ^[0]__clear {
      z-index 12
    }
  }

  &--drop-top {
    border-radius 0 0 10px 10px

    & ^[0]__drop {
      border-radius 10px 10px 0 0
      absolute top left
      bottom initial
      transform translateY(calc(-100% + 2px))
    }

    & ^[0]__clear {
      z-index 12
    }
  }

  &__clear {
    @extend .date-select__action
    transform translateX(-50%)
    z-index 1
  }

  &__field {
    padding 5px 12px 25px
    font-size: 0.8750em;
    line-height: 20px;
    z-index 1
    color $dark
    width 100%
    height 100%
    display inline-flex
    flex-shrink 0
  }

  &__selected {
    display grid
    grid-gap 4px
    grid-template-columns auto auto
    +below(480px) {
      display none
    }

    &::placeholder {
      color $dark
    }

    &--one {
      grid-template-columns auto
    }

    .icon {
      width 12px
      height 12px
      display flex
      align-items center
      justify-content center
    }

    &-item {
      cursor pointer
      background var(--input_focused_border)
      border-radius 10px
      padding 2px 4px
      font-size: 0.8750em;
      line-height: 22px;
      text-align: center;
      color: $black;
      text-decoration none

      &:hover {
        color $black
      }

      &:first-child {
        display grid
        grid-template-columns auto 12px
        align-items center
      }
    }
  }

  &:not(.input-default)
  &:not(.date-select--input) {
    border-color $gray1 !important;

    & ^[0]__title {
      font-size: 1em;
      line-height: 20px;
      width: 100%;
      height 100%
      padding 20px
    }
  }
}
</style>
