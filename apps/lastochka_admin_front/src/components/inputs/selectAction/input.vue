<template>
  <div
    v-click-outside="clickOutside"
    class="select-action"
    :class="{
      'select-action--values-visible': !focused && multiple && modelValue.length,
      'select-action--focused': focused,
      'select-action--small': small,
      'select-action--error': errors.length,
      'select-action--multiple': multiple,
      'select-action--list-top': listPosition === 'top',
      'select-action--list-bottom': listPosition === 'bottom',
    }"
  >
    <label class="select-action__title" :for="id" v-if="title">
      {{ title }}
      <span v-if="required">*</span>
    </label>
    <div class="select-action__field" ref="input">
      <label class="select-action__field-label" :for="id" aria-hidden="true"></label>
      <input
        :id="id"
        type="text"
        class="select-action__input"
        :value="inputValue"
        @input="query = $event.target.value"
        @focus="focus"
        :placeholder="placeholder"
      />
      <div class="select-action__values" v-if="multiple">
        <label :for="id"></label>
        <button type="button" v-for="(value, i) in modelValue" :key="i" @click="remove(i)">
          <span>{{ value[labelName] }}</span>
          <IconComponent category="v-outline" name="close" />
        </button>
      </div>
      <button class="select-action__clear" type="button" @click="clear" v-if="canShowClear">
        <IconComponent category="v-outline" name="close" />
      </button>
      <label :for="id" class="select-action__arrow">
        <IconComponent category="v-outline" name="arrow-down-1" />
      </label>
    </div>
    <button class="select-action__button btn" @click="action">
      <IconComponent category="v-outline" name="arrow-right-1" />
    </button>
    <SelectListComponent :query="query" v-bind="$props" @update:model-value="listUpdate" />
    <div class="select-action__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="select-action__messages" v-if="messages.length">
      <span v-for="(message, i) in messages" :key="i">{{ message }}</span>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import SelectListComponent from "@/components/inputs/select/list.vue";

const uid = () => Date.now().toString(36) + Math.random().toString(36).substring(2);

export default {
  name: "SelectActionComponent",
  components: { SelectListComponent, IconComponent },
  emits: ["update:model-value", "click"],
  props: {
    title: {
      type: String,
      default: "",
    },
    placeholder: {
      type: String,
      default: "",
    },
    labelName: {
      type: String,
      default: "title",
    },
    modelValue: [Array, Object],
    messages: {
      type: Array,
      default: () => [],
    },
    errors: {
      type: Array,
      default: () => [],
    },
    options: {
      type: Array,
      default: () => [],
    },
    required: Boolean,
    preferredDropPosition: String,
    small: Boolean,
    multiple: Boolean,
    clearable: Boolean,
  },
  data: () => ({
    query: "",
    listPosition: null,
    id: uid(),
    focused: false,
  }),
  computed: {
    canShowClear() {
      if (this.clearable) {
        if (this.multiple) {
          return this.modelValue.length;
        }
        return this.modelValue;
      }
      return false;
    },
    inputValue() {
      if (this.focused) {
        return this.query;
      }
      if (this.modelValue) {
        return this.modelValue[this.labelName];
      }
      return "";
    },
  },
  methods: {
    focus() {
      this.focused = true;
      this.openDrop();
    },
    openDrop() {
      if (this.preferredDropPosition) {
        this.listPosition = this.preferredDropPosition;
        return;
      }
      if (this.$refs.input) {
        const height = 200; // Минимум пространства
        const bottom = this.$refs.input.getBoundingClientRect().bottom;
        if (bottom + height > window.innerHeight) {
          this.listPosition = "top";
          return;
        }
        this.listPosition = "bottom";
      }
    },
    listUpdate($event) {
      this.$emit("update:model-value", $event);
      if (!this.multiple) {
        this.close();
      }
    },
    close() {
      this.focused = false;
      this.listPosition = null;
      this.query = "";
    },
    clickOutside() {
      this.close();
    },
    remove(i) {
      let array = this.modelValue;
      array.splice(i, 1);
      this.$emit("update:model-value", array);
    },
    clear() {
      this.$emit("update:model-value", this.multiple ? [] : null);
    },
    action() {
      this.$emit("click", this.modelValue);
    },
  },
  directives: {
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
.select-action {
  display inline-flex
  grid-gap 15px
  position relative
  padding 15px 20px
  background-color var(--white)
  border-radius 5px
  border 1px solid var(--gray-dark)

  .select-list {
    position absolute
    height 0
    width 0
    z-index 1
    opacity 0
    visibility hidden
    transition opacity .2s

    // ^[0]--focused & {  = .select--focused .select .select-list ¯\_(ツ)_/¯

    ^[0]--focused& { // = .select--focused .select-list ¯\_(ツ)_/¯
      opacity 1
      visibility: visible;
      height auto
      width 100%
      max-height 200px
      left 0
      //border: 2px solid var(--dark);
      //box-shadow: 0 0 0 3px var(--main-o15);
    }

    ^[0]--list-bottom& {
      clip-path inset(0px -3px -3px -3px)
      left: -1px;
      bottom: 3px;
      border-top none
      box-sizing content-box
      transform translateY(100%)
      border-top-left-radius 0
      border-top-right-radius 0
    }

    ^[0]--list-top& {
      clip-path inset(-3px -3px 0 -3px)
      box-sizing content-box
      left -1px
      top 3px
      border-bottom none
      transform translateY(-100%)
      border-bottom-left-radius 0
      border-bottom-right-radius 0
    }
    &__item {
      button {
        padding 5px 20px
      }
    }
  }

  &__title {
    font-weight: 700;
    font-size: 0.865em;
    line-height: 24px;
    color: var(--dark);

    ^[0]--error & {
      color var(--red)
    }

    > span {
      color: var(--red);
    }
  }

  &__clear {
    height 100%
    background none
    border none
    padding 0 15px
    margin-right -15px
    display inline-flex
    align-items center
    justify-content center
    z-index 1

    ^[0]--focused & {
      display none
    }

    .icon svg {
      width: 16px;
      height: 16px;
    }
  }

  &__field-label {
    position absolute
    left 0
    top 0
    width 100%
    height 100%
    cursor text
  }

  &__field {
    display flex
    background var(--gray)
    border 1px solid var(--dark-light)
    border-radius 5px
    height 60px
    transition background .2s, box-shadow .2s, border-color .2s
    align-items stretch
    justify-content stretch
    padding 1px 16px
    position relative
    padding-right 1px
    width 100%
    overflow hidden

    ^[0]--small & {
      height 50px
      border-color var(--gray-dark)
    }

    ^[0]--values-visible & {
      background: var(--white);
    }

    ^[0]--focused & {
      padding 0 15px
      padding-right 0
      background: var(--white);
      border: 2px solid var(--dark);
      box-shadow: 0 0 0 3px var(--main-o15);
    }
    focusBottom = '^[0]--list-bottom^[0]--focused &';

    {focusBottom} {
      //padding-bottom 2px
      //border-bottom 0
      //border-bottom-left-radius 0
      //border-bottom-right-radius 0
    }

    focusTop = '^[0]--list-top^[0]--focused &';

    {focusTop} {
      //padding-top 2px
      //border-top 0
      //border-top-left-radius 0
      //border-top-right-radius 0
    }

    errorAndFocus = '^[0]--error^[0]--focused &';

    {errorAndFocus} {
      box-shadow: 0 0 0 3px var(--red-o15);
    }

    ^[0]--error & {
      background: var(--white);
      border-color var(--red);
    }
  }

  &__input {
    appearance none
    border none
    background none
    outline none
    font-weight: 500;
    font-size: 0.875em;
    line-height: 20px;
    color: var(--dark);
    height 100%
    width 100%
    opacity 1
    overflow visible

    ^[0]--values-visible & {
      width 0
      height 0
      opacity 0
      overflow hidden
    }
  }

  &__arrow {
    height 100%
    padding 0 15px
    display inline-flex
    align-items center
    justify-content center
    z-index 1
    cursor pointer

    .icon svg {
      width: 10px;
      height: 10px;
      transition .2s

      ^[0]--focused & {
        transform rotate(180deg)
      }
    }
  }

  &__values {
    height 100%
    width 100%
    overflow hidden
    align-items center
    gap 5px
    display none
    position relative

    ^[0]--values-visible & {
      display flex
    }

    label {
      position absolute
      left 0
      top 0
      width 100%
      bottom 0
      z-index 1
      cursor text
    }

    button {
      z-index 2
      background: var(--gray);
      border: 1px solid var(--gray-dark);
      border-radius: 5px;
      display inline-flex
      align-items center
      gap 5px
      padding 2px 5px
      font-weight: 500;
      font-size: 0.75em;
      line-height: 20px;
      color: var(--dark);

      .icon {
        width 12px
        height 12px

        svg path {
          fill var(--dark)
        }
      }
    }
  }
  &__button {
    width 60px
    height 60px
    border-radius 5px

    ^[0]--small & {
      height 50px
      width 50px
      border-color var(--gray-dark)
    }

    .icon {
      width 24px
      height 24px
    }
  }
  &__errors,
  &__messages {
    font-weight: 400;
    font-size: 0.75em;
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
