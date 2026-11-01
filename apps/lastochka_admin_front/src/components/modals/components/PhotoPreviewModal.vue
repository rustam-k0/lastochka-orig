<template>
  <div class="photo-preview mod">
    <div class="mod__header">
      <span class="mod__title">{{ title || 'Фото товара' }}</span>
      <button class="mod__close" type="button" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>
    <div class="photo-preview__body">
      <img :src="src" :alt="title" class="photo-preview__img" />
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "PhotoPreviewModal",
  components: { IconComponent },
  emits: ["close"],
  props: {
    src: {
      type: String,
      required: true,
    },
    title: {
      type: String,
      default: null,
    },
  },
};
</script>

<style lang="stylus">
.photo-preview {
  width auto
  max-width 90vw

  &__body {
    padding 20px
    display flex
    align-items center
    justify-content center
  }

  &__img {
    max-width 80vw
    max-height 80vh
    object-fit contain
    border-radius 6px
  }
}
</style>
