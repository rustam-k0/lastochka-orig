<template>
  <div
    v-click-outside="close"
    class="date-input"
    :class="{
      'date-input--focused': focused,
      'date-input--small': small,
      'date-input--error': errors.length,
      'date-input--list-top': listPosition === 'top',
      'date-input--list-bottom': listPosition === 'bottom',
    }"
  >
    <label class="date-input__title" :for="id" v-if="title">
      {{ title }}
      <span v-if="required">*</span>
    </label>
    <div class="date-input__container">
      <div class="date-input__field" ref="input">
        <label class="date-input__field-label" :for="id" aria-hidden="true"></label>
        <input
          :id="id"
          :type="type"
          v-maska="mask"
          :placeholder="placeholder"
          :value="inputValue"
          @blur="focused = false"
          @focus="handleFocus"
          @input="handleInput"
        />
        <button v-if="canShowClear" class="date-input__clear" type="button" @click.prevent="clear">
          <IconComponent name="close-fill" />
        </button>
        <button type="button" @click.prevent="openDrop">
          <IconComponent name="calendar-event-line" />
        </button>
      </div>
      <DatePicker
        v-if="opened"
        :locale="{ id: 'ru', masks: { weekdays: 'WW' } }"
        :first-day-of-week="2"
        :modelValue="modelValue"
        @update:model-value="pickerChange"
        :is-range="range"
      />
    </div>
    <div class="input__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="input__messages" v-if="messages.length">
      <span v-for="(message, i) in messages" :key="i">{{ message }}</span>
    </div>
  </div>
</template>

<script>
import { DatePicker } from "v-calendar";
import "v-calendar/dist/style.css";
import { maska } from "maska";
import IconComponent from "@/components/IconComponent.vue";
import dayjs from "dayjs";
import customParseFormat from "dayjs/plugin/customParseFormat";

const uid = () => Date.now().toString(36) + Math.random().toString(36).substring(2);

export default {
  name: "DateInput",
  emits: ["update:model-value"],
  props: {
    title: {
      type: String,
      default: "",
    },
    format: {
      type: String,
      default: "DD.MM.YYYY",
    },
    placeholder: {
      type: String,
      default: "",
    },
    type: {
      type: String,
      default: "text",
    },
    modelValue: String,
    messages: {
      type: Array,
      default: () => [],
    },
    errors: {
      type: Array,
      default: () => [],
    },
    required: Boolean,
    small: Boolean,
    range: Boolean,
    clearable: Boolean,
  },
  data: () => ({
    id: uid(),
    listPosition: null,
    inputValue: null,
    focused: false,
    opened: false,
  }),
  mounted() {
    this.syncInputValue(this.modelValue);
  },
  watch: {
    /**
     * Значение может прийти извне — например, когда одну и ту же дату
     * редактируют два разных поля (период статистики магазина). Без этого
     * второе поле остаётся визуально пустым, хотя значение применилось.
     * Пока поле в фокусе, текст не трогаем: пользователь печатает, и его же
     * ввод возвращается сюда эхом — переформатирование сбрасывало бы каретку.
     */
    modelValue(value) {
      if (this.focused) return;
      this.syncInputValue(value);
    },
  },
  computed: {
    mask() {
      return this.format.replace(/([a-zA-Z])/gm, "#");
    },
    canShowClear() {
      if (this.clearable) {
        return this.modelValue;
      }
      return false;
    },
  },
  methods: {
    /** Привести отображаемый текст поля к переданному значению. */
    syncInputValue(value) {
      if (!value) {
        this.inputValue = null;
        return;
      }
      if (this.range) {
        this.inputValue =
          value.start && value.end
            ? `${dayjs(value.start).format("DD.MM.YYYY")} - ${dayjs(value.end).format("DD.MM.YYYY")}`
            : null;
        return;
      }
      this.inputValue = dayjs(value).format(this.format);
    },
    clear() {
      this.inputValue = null;
      this.$emit("update:model-value", null);
    },
    pickerChange($event) {
      if (this.range) {
        // DatePicker эхом эмитит null, когда modelValue сброшен извне (clear) при открытом пикере
        if ($event && $event.start && $event.end) {
          let start = dayjs($event.start).format("DD.MM.YYYY");
          let end = dayjs($event.end).format("DD.MM.YYYY");
          this.inputValue = `${start} - ${end}`;
        } else {
          this.inputValue = null;
        }
      } else {
        this.inputValue = $event ? dayjs($event).format(this.format) : null;
      }
      this.$emit("update:model-value", $event);
    },
    openDrop() {
      if (this.$refs.input) {
        this.opened = true;
        const height = 270; // Минимум пространства
        const bottom = this.$refs.input.getBoundingClientRect().bottom;
        if (bottom + height > window.innerHeight) {
          this.listPosition = "top";
          return;
        }
        this.listPosition = "bottom";
      }
    },
    close() {
      this.opened = false;
      this.listPosition = null;
    },
    handleFocus() {
      this.focused = true;
      this.opened = false;
    },
    handleInput($event) {
      this.inputValue = $event.target.value;
      let regex = new RegExp(this.mask.replaceAll("#", "\\d"));
      if (regex.exec($event.target.value)) {
        dayjs.extend(customParseFormat);
        let date = dayjs(regex.exec($event.target.value)[0], this.format);
        if (regex.exec($event.target.value) && date.isValid()) {
          this.$emit("update:model-value", date.format().toString());
        }
      }
    },
  },
  components: {
    IconComponent,
    DatePicker,
  },
  directives: {
    maska,
    "click-outside": {
      mounted(el, binding) {
        el.clickOutsideEvent = (event) => {
          if (!(el === event.target || el.contains(event.target))) {
            binding.value(event);
          }
        };
        document.body.addEventListener("click", el.clickOutsideEvent);
      },
      beforeUnmount(el) {
        document.body.removeEventListener("click", el.clickOutsideEvent);
      },
    },
  },
};
</script>

<style lang="stylus">
.date-input {
  display inline-grid
  grid-gap 5px
  width 100%

  &__container {
    position relative
    width 100%
  }

  .vc-container {
    position absolute
    z-index 10

    ^[0]--list-bottom& {
      bottom 0
      border-top-right-radius 0
      border-top-left-radius 0
      transform translateY(100%)
    }

    ^[0]--list-top& {
      top 0
      border-bottom-right-radius 0
      border-bottom-left-radius 0
      transform translateY(-100%)
    }
  }

  &__title {
    font-weight: 400;
    font-size: 0.8125em;
    line-height: 20px;
    color: var(--dark);

    ^[0]--error & {
      color var(--red)
    }

    > span {
      color: var(--red);
    }
  }

  &__field-label {
    position absolute
    left 0
    top 0
    width 100%
    height 100%
    cursor text
    background: var(--white)
    border 1px solid var(--border-color)
    border-radius: 6px;
  }

  &__field {
    display flex
    //background var(--gray)
    //border 1px solid var(--dark-light)
    border-radius 5px
    gap 15px
    transition background .2s, box-shadow .2s, border-color .2s
    align-items stretch
    justify-content stretch
    padding-left 16px
    position relative
    padding-right 1px
    height 48px
    z-index 2

    ^[0]--small & {
      height 50px
      border-color var(--gray-dark)
    }

    ^[0]--focused & {
      //padding 0 15px
      //padding-right 0
      //background: var(--white);
      //border: 2px solid var(--dark);
      //box-shadow: 0 0 0 3px var(--main-o15);
    }
    errorAndFocus = '^[0]--error^[0]--focused &';

    {errorAndFocus} {
      box-shadow: 0 0 0 3px var(--red-o15);
    }

    ^[0]--error & {
      background: var(--white);
      border-color var(--red);
    }

    input {
      appearance none
      border none
      background none
      outline none
      height 100%
      width 100%
      font-weight: 500;
      font-size: 0.875rem;
      line-height: 20px;
      color: var(--dark);
      z-index 1
    }

    button {
      height 100%
      background none
      border none
      padding 15px
      display inline-flex
      align-items center
      justify-content center
      z-index: 1;
    }

    .icon svg {
      width 16px
      height 16px

      //path {
      //  fill var(--dark-light)
      //}
    }
  }

  &__clear {
    margin-right -30px
  }

  &__errors,
  &__messages {
    font-weight: 400;
    font-size: 0.75rem;
    line-height: 15px;
    color: var(--dark);
    opacity: 0.4;
    display grid
    grid-gap 3px
  }

  &__errors {
    opacity 1
    color var(--red)
  }
}
</style>
