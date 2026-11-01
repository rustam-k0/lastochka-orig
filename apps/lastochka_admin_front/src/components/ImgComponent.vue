<template>
  <img
    :src="src || source"
    :alt="head_img ? head_img.alt : ''"
    loading="lazy"
    class="img"
    :class="{ lazy: loader, loaded, 'img--contain': contain }"
    @load="loaded = true"
    @error="loaded = true"
  />
</template>

<script>
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "ImgComponent",
  props: {
    src: String,
    head_img: Object,
    quality: {
      type: String,
      default: "90",
    },
    width: [Number, String],
    height: [Number, String],
    loader: {
      type: Boolean,
      default: true,
    },
    contain: Boolean,
  },
  data() {
    return {
      loaded: false,
    };
  },
  computed: {
    endpoint() {
      return useDefaultStore().HOST_ENDPOINT;
    },
    source() {
      if (this.head_img) {
        const urlParams = new URLSearchParams();
        if (this.width) urlParams.append("width", "" + this.width);
        if (this.height) urlParams.append("height", "" + this.height);
        if (this.quality) urlParams.append("quality", this.quality);
        return `${this.endpoint}/action/img/${this.head_img.id}?${urlParams.toString()}`;
      }
      return "/public/assets/images/no-image.png";
    },
    // source() {
    //   if (this.head_img && typeof document !== "undefined") {
    //     let img = document.querySelector("#img_lazy");
    //     const urlParams = new URLSearchParams();
    //     let rect = img.getBoundingClientRect();
    //     urlParams.append("width", "" + rect.width || 0);
    //     urlParams.append("height", "" + rect.height || 0);
    //     urlParams.append("quality", this.quality);
    //     return `https://sasa.comrades.dev/action/img/${this.head_img.id}?${urlParams.toString()}`;
    //   }
    //   return "/static/images/no-image.png";
    // },
  },
};
</script>

<style lang="stylus">
.img {
  object-fit cover
  object-position center

  &--contain {
    object-fit contain
  }
}

.lazy {
  opacity 0
  transition var(--transition)

  &.loaded {
    opacity 1
  }
}
</style>
