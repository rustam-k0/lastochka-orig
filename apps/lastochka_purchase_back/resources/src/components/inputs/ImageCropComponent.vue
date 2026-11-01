<template>
  <div class="crop">
    <label v-if="title" class="crop__title">{{ title }}</label>
    <div class="crop__body">
      <input v-if="!localValue" ref="image" accept="image/*" class="ipt" type="file" @change="init" />
      <img v-if="localValue" :src="localValue.path" alt="Выбранное изображение" class="crop__image" />
      <div v-if="localValue" class="crop__input">
        <div class="crop__name">
          <a :href="localValue.path" class="crop__image-title" target="_blank">
            {{ localValue.origin_name }}
          </a>
          <span class="crop__image-size">{{ localValue.size }}</span>
        </div>
        <InputComponent
          v-model.trim="localValue.alt"
          autofocus
          title="Название фотографии"
          :messages="altMessages"
        />
        <button class="btn btn--medium btn--red" type="button" @click="clear">Очистить</button>
      </div>
    </div>
    <ul v-if="errors && errors.length" class="input__errors">
      <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
    </ul>
    <ul v-if="messages && messages.length" class="input__errors input__errors--messages">
      <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
    </ul>
  </div>
</template>

<script>
import InputComponent from "./InputComponent.vue";
import CropImageModal from "../modals/components/CropImageModal.vue";

export default {
  name: "ImageCropComponent",
  components: { InputComponent },
  props: {
    width: Number,
    height: Number,
    title: {
      type: String,
    },
    value: {
      type: Object,
    },
    errors: {
      type: Array,
    },
    altMessages: {
      type: Array,
    },
    messages: {
      type: Array,
    },
  },
  data() {
    return {
      localValue: this.value,
    };
  },
  computed: {
    aspectRatio() {
      if (this.width && this.height) {
        return this.width / this.height;
      }
      return NaN;
    },
  },
  watch: {
    value: {
      immediate: true,
      handler(val) {
        this.localValue = val;
      },
    },
  },
  methods: {
    emit() {
      this.$emit("input", this.localValue);
    },
    clear() {
      if (confirm("Вы уверены?")) {
        this.localValue = null;
        this.$emit("input", this.localValue);
      }
    },
    init() {
      if (this.$refs.image.files.length) {
        const reader = new FileReader();
        reader.onloadend = () => {
          this.$store.state._modals.push({
            component: CropImageModal,
            options: {
              aspectRatio: this.aspectRatio,
              data: {
                imageBase64: reader.result,
                image: this.$refs.image.files[0],
              },
              callback: (res) => {
                this.localValue = {
                  path: res.path,
                  origin_name: res.origin_name,
                  alt: res.alt,
                  size: res.size,
                  type: res.type,
                };
                this.emit("input", this.localValue);
              },
            },
          });
        };
        if (this.$refs.image.files[0]) {
          reader.readAsDataURL(this.$refs.image.files[0]);
        }
      }
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.crop {
  display grid
  grid-gap 5px

  &__title {
    font-size 0.875em
    font-family $font_regular
    display block
    line-height 1.5em
    color $gray3
  }

  &__body {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    width 100%
  }

  &__image {
    width: 220px;
    height: 220px;
    object-fit: contain;
    object-position: center;
    background: rgba($main_color, .1);
    border-radius: 10px;
    border: 1px solid $gray1;
  }

  &__input {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 10px;
  }

  &__name {
    display: flex;
    align-items: center;
    gap 10px
    link($main_color)
  }
}
</style>
