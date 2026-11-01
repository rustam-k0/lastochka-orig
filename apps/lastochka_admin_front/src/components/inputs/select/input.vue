<template>
  <div
    v-click-outside="close"
    class="select"
    :class="{
      'select--values-visible': !focused && multiple && modelValue.length,
      'select--focused': focused,
      'select--small': small,
      'select--disabled': disabled,
      'select--big': big,
      'select--error': errors.length,
      'select--multiple': multiple,
      'select--list-top': listPosition === 'top',
      'select--list-bottom': listPosition === 'bottom',
    }"
  >
    <label class="select__title" :for="id" v-if="title">
      {{ title }}
      <span v-if="required">*</span>
    </label>
    <div class="select__input-container">
      <div class="select__field" ref="input">
        <label class="select__field-label" :for="id" aria-hidden="true"></label>
        <input
          :id="id"
          type="text"
          class="select__input"
          :value="inputValue"
          @input="updateSearch($event.target.value)"
          @focus="focus"
          @blur="$emit('blur', $event.target.value)"
          :placeholder="placeholder"
          :disabled="disabled"
        />
        <div class="select__values" v-if="multiple">
          <label :for="id"></label>
          <button type="button" v-for="(value, i) in modelValue" :key="i" @click="remove(i)">
            <span>{{ getOptionTitle(value) }}</span>
            <IconComponent name="close-fill" />
          </button>
        </div>
        <button class="select__clear" type="button" @click="clear" v-if="canShowClear">
          <IconComponent name="close-fill" />
        </button>
        <label v-if="!disabled" :for="id" class="select__arrow">
          <IconComponent name="arrow-down-s-fill" />
        </label>
      </div>
      <SelectListComponent :query="query" v-bind="$props" @update:model-value="listUpdate">
        <template v-slot="{ item, i }">
          <slot :i="i" :item="item"></slot>
        </template>
      </SelectListComponent>
    </div>
    <div class="select__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="select__messages" v-if="messages.length">
      <span v-for="(message, i) in messages" :key="i">{{ message }}</span>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import SelectListComponent from "@/components/inputs/select/list.vue";

const uid = () => Date.now().toString(36) + Math.random().toString(36).substring(2);

export default {
  name: "SelectComponent",
  components: { SelectListComponent, IconComponent },
  emits: ["update:model-value", "update:search", "blur"],
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
      type: [String, Array],
      default: "title",
    },
    loading: {
      type: Boolean,
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
    small: Boolean,
    big: Boolean,
    multiple: Boolean,
    clearable: Boolean,
    disabled: Boolean,
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
        return this.getOptionTitle(this.modelValue);
      }
      return "";
    },
  },
  methods: {
    updateSearch(e) {
      this.query = e;
      this.$emit("update:search", e);
    },
    focus() {
      this.focused = true;
      this.openDrop();
    },
    openDrop() {
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
      if (!this.disabled) {
        this.$emit("update:model-value", $event);
        if (!this.multiple) {
          this.close();
        }
      }
    },
    close() {
      this.focused = false;
      this.listPosition = null;
      this.query = "";
    },
    remove(i) {
      if (!this.disabled) {
        let array = JSON.parse(JSON.stringify(this.modelValue));
        array.splice(i, 1);
        this.$emit("update:model-value", array);
      }
    },
    clear() {
      if (!this.disabled) {
        this.$emit("update:model-value", this.multiple ? [] : null);
      }
    },
    getOptionTitle(option) {
      if (Array.isArray(this.labelName)) {
        return option[this.labelName[0]][this.labelName[1]];
      }
      return option[this.labelName];
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
.select {
  width: 100%;
  display inline-grid
  grid-gap 5px
  position relative

  .select-list {
    position absolute
    height 0
    width 0
    z-index 10
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
      box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
      //box-shadow: 0 0 0 3px var(--main-o15);
    }

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
    font-size: 0.813rem
    line-height: 18px;
    color: var(--black-2)

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

    ^[0]--big & {
      align-items flex-start
      padding-top:16px
    }

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
    border-radius: 6px

    ^[0]--disabled & {
      background: var(--neutral-50)
    }
  }

  &__field {
    display flex
    background: var(--white)
    border: 1px solid var(--border-color);
    border-radius 5px
    height 48px
    transition background .2s, box-shadow .2s, border-color .2s
    align-items stretch
    justify-content stretch
    padding 1px 16px
    position relative
    padding-right 1px
    width 100%
    overflow hidden
    z-index: 2;

    ^[0]--small & {
      height 36px
      border-color var(--gray-dark)
    }

    ^[0]--big & {
      height 150px
    }

    ^[0]--values-visible & {
      background: var(--white);
      height auto
      align-items center
    }

    ^[0]--focused & {
      padding 0 15px
      padding-right 0
      background: var(--white);
      //border: 2px solid var(--dark);
      //box-shadow: 0 0 0 3px var(--main-o15);
      z-index 11
    }
    focusBottom = '^[0]--list-bottom^[0]--focused &';

    {focusBottom} {
      border-bottom-left-radius 0
      border-bottom-right-radius 0
    }

    focusTop = '^[0]--list-top^[0]--focused &';

    {focusTop} {
      border-top-left-radius 0
      border-top-right-radius 0
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
    font-size: 0.875rem;
    line-height: 20px;
    color: var(--dark);
    height 100%
    width 100%
    opacity 1
    overflow visible

    ^[0]--big & {
      padding-top:16px
      display flex
      align-items start
    }

    ^[0]--disabled & {
      color var(--body-color-o5)
      z-index 1
    }

    &::placeholder {
      font-weight: 400;
      font-size: 0.875rem
      line-height: 16px;
      color: var(--gray-2)
    }

    ^[0]--values-visible & {
      width 0
      height 0
      opacity 0
      overflow hidden
    }
  }

  &__input-container {
    position relative
    width 100%
    // min-width:0 разрешает grid-элементу сжаться до ширины родителя.
    // Без него контейнер раздувается под min-content тегов (multiple + values-visible)
    // и поле наезжает на соседнее поле справа (см. фильтры заказов).
    min-width 0
  }

  &__arrow {
    height 100%
    padding 0 15px
    display inline-flex
    align-items center
    justify-content center
    z-index 1
    cursor pointer

    ^[0]--big & {
      align-items flex-start
      padding-top:19px
    }

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
      flex-wrap wrap
      padding: 10px 0
    }

    ^[0]--big & {
      align-items flex-start
      padding-top:16px
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
      background: var(--blue-light);
      border: 1px solid transparent;
      border-radius: 5px;
      display inline-flex
      align-items center
      gap 5px
      padding 4px 8px
      font-weight: 500;
      font-size: 0.75rem;
      white-space nowrap
      line-height: 20px;
      color: var(--dark);

      .icon {
        width 18px
        height 18px
        padding: 3px
        border-radius: 999px;
        background var(--white)

        svg path {
          fill var(--dark)
          width 12px
          height 12px
        }
      }
    }
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
