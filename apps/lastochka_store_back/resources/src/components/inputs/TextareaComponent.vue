<template>
  <div class="textarea__container">
    <label
      :class="{
        'textarea--default': notEmpty || focused,
        'textarea--error': errors.length,
      }"
      class="textarea"
    >
      <span class="textarea__title">{{ title }}</span>
      <textarea
        ref="input"
        v-model="text"
        :autofocus="autofocus"
        :rows="rows"
        autocomplete="new-password"
        class="textarea__field"
        @blur="blur"
        @click="$emit('click')"
        @focus="focus"
        @input="$emit('input', $event.target.value)"
      />
    </label>
    <ul v-if="errors && errors.length" class="textarea__errors">
      <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
    </ul>
    <ul v-if="messages && messages.length" class="textarea__errors editor__errors--messages">
      <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
    </ul>
  </div>
</template>

<script>
export default {
  name: "TextareaComponent",
  props: {
    title: {
      type: String,
      required: true,
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
    rows: {
      type: [Number, String],
      default() {
        return 3;
      },
    },
    value: [String, Number],
    autofocus: Boolean,
  },
  data() {
    return {
      focused: false,
      text: this.value,
    };
  },
  watch: {
    value: {
      immediate: true,
      handler(val) {
        this.text = val;
      },
    },
  },
  computed: {
    notEmpty() {
      if (typeof this.text === "string") {
        return this.text && this.text.length;
      }
      return this.text !== null;
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
};
</script>

<style lang="stylus">
@import "~@/styles/elements/input.styl"
.textarea {
  @extends .input
  height auto
}
</style>
