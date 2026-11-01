<template>
  <div class="input__container">
    <label
      :class="{
        'input--default': notEmpty || focused,
        'input--error': errors.length,
      }"
      class="input"
    >
      <span class="input__title">{{ title }}</span>
      <input
        ref="input"
        v-mask="mask"
        :autofocus="autofocus"
        :max="max"
        :min="min"
        :type="type"
        :value="value"
        autocomplete="new-password"
        class="input__field"
        @blur="blur"
        @click="$emit('click')"
        @focus="focus"
        @input="$emit('input', $event.target.value)"
      />
      <button v-if="$slots.action" class="input__action" type="button" @click.prevent="$emit('submit')">
        <slot name="action" />
      </button>
    </label>
    <ul v-if="errors && errors.length" class="input__errors">
      <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
    </ul>
    <ul v-if="messages && messages.length" class="input__errors input__errors--messages">
      <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
    </ul>
  </div>
</template>

<script>
import { VueMaskDirective } from "v-mask";

export default {
  name: "InputComponent",
  props: {
    title: {
      type: String,
      required: true,
    },
    type: {
      type: String,
      default() {
        return "text";
      },
    },
    errors: {
      type: Array,
      default() {
        return [];
      },
    },
    messages: {
      type: Array,
      default() {
        return [];
      },
    },
    value: [String, Number],
    mask: String,
    min: Number,
    max: Number,
    autofocus: Boolean,
  },
  data() {
    return {
      focused: false,
    };
  },
  computed: {
    notEmpty() {
      if (typeof this.value === "string") {
        return this.value && this.value.length;
      }
      return this.value !== null;
    },
  },
  methods: {
    focus() {
      if (!this.focused) {
        this.focused = true;
      }
      this.$emit("focus");
    },
    blur() {
      if (this.focused) {
        this.focused = false;
      }
      this.$emit("blur");
    },
  },
  directives: {
    mask: VueMaskDirective,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/elements/input.styl"
</style>
