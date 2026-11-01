<template>
  <figure :class="`icon ${category ? category + '-' : ''}${name}-icon`" v-if="!error">
    <InlineSvg
      :src="`/distro/svg/${collection}/${category ? category + '/' : ''}${name}.svg`"
      @error="error = true"
    />
  </figure>
</template>

<script>
import InlineSvg from "vue-inline-svg";

export default {
  name: "IconComponent",
  props: {
    name: String,
    category: String,
    collection: {
      type: String,
      default() {
        return "remix";
      },
    },
  },
  data() {
    return {
      error: false,
    };
  },
  components: {
    InlineSvg,
  },
};
</script>
