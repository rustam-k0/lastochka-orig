<template>
  <div class="cropper">
    <div class="cropper__header">
      <h3 class="cropper__title">Обрезка изображения</h3>
      <button v-if="!loading" class="cropper__close" type="button" @click="$emit('close')">
        <Icon name="close" />
      </button>
    </div>
    <div class="cropper__body">
      <div v-if="data.imageBase64">
        <vue-cropper
          ref="cropper"
          :src="data.imageBase64"
          :viewMode="1"
          :aspectRatio="aspectRatio"
          alt="Изображение"
          class="cropper__container"
        />
        <div v-if="!loading" class="cropper__buttons">
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(10, 0)">
            <Icon category="system" name="arrow-left-s-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(0, 10)">
            <Icon category="system" name="arrow-up-s-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(0, -10)">
            <Icon category="system" name="arrow-down-s-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(-10, 0)">
            <Icon category="system" name="arrow-right-s-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.rotate(-45)">
            <Icon category="system" name="anticlockwise-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.rotate(45)">
            <Icon category="system" name="clockwise-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="scaleHorizontal">
            <Icon category="system" name="arrow-left-right-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="scaleVertical">
            <Icon category="system" name="arrow-up-down-line" />
          </button>
          <button
            class="btn btn--small btn--main-outline"
            type="button"
            @click="$refs.cropper.relativeZoom(0.1)"
          >
            <Icon category="system" name="zoom-in-line" />
          </button>
          <button
            class="btn btn--small btn--main-outline"
            type="button"
            @click="$refs.cropper.relativeZoom(-0.1)"
          >
            <Icon category="system" name="zoom-out-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.reset()">
            <Icon category="system" name="refresh-line" />
          </button>
        </div>
      </div>
    </div>
    <div class="cropper__footer">
      <button class="btn btn--main btn--medium" type="button" @click="cropImage">
        <LoadingIndicator v-show="loading" title="Обработка" />
        <span v-show="!loading">Обрезать</span>
      </button>
    </div>
  </div>
</template>

<script>
import VueCropper from "vue-cropperjs";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import IMAGE_UPLOAD_CROP from "@/graphql/mutations/ImageUploadCrop.graphql";
import Icon from "@/components/IconComponent.vue";

export default {
  name: "CropImageComponent",
  props: {
    aspectRatio: Number,
    data: Object,
    callback: Function,
  },
  components: {
    Icon,
    VueCropper,
    LoadingIndicator,
  },
  data() {
    return {
      loading: false,
      scaleH: -1,
      scaleV: -1,
    };
  },
  methods: {
    /**
     * Отражение по горизонтали
     */
    scaleHorizontal() {
      this.$refs.cropper.scaleX(this.scaleH);
      this.scaleH = this.scaleH > 0 ? -1 : 1;
    },
    /**
     * Отражение по вертикали
     */
    scaleVertical() {
      this.$refs.cropper.scaleY(this.scaleV);
      this.scaleV = this.scaleV > 0 ? -1 : 1;
    },
    close() {
      this.$emit("close");
    },
    cropImage() {
      if (!this.loading) {
        this.loading = true;
        let variables = {
          image: this.data.image,
        };
        let crop = this.$refs.cropper.getData();
        Object.keys(crop).forEach((key) => {
          variables[key] = parseInt(crop[key]);
        });
        this.$apollo
          .mutate({
            mutation: IMAGE_UPLOAD_CROP,
            variables: variables,
          })
          .then(({ data }) => {
            if (data.ImageUploadCrop) {
              let res = data.ImageUploadCrop;
              res.alt = null;
              this.callback(res);
            }
            this.loading = false;
            this.close();
          })
          .catch(({ graphQLErrors }) => {
            graphQLErrors.forEach((err) => {
              if (err.extensions.category === "validation") {
                Object.keys(err.extensions.validation).forEach((key) => {
                  this.$notify({
                    title: "Произошла ошибка",
                    text: err.extensions.validation[key],
                    type: "error",
                  });
                });
              }
            });
            this.close();
          });
      }
    },
  },
};
</script>
<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
@import "~cropperjs/dist/cropper.css";
.cropper {
  max-width: 588px;
  width 100%
  z-index 2
  background: $white;
  box-shadow: 0 16px 24px rgba($black, .06), 0px 2px 6px rgba($black, .04), 0px 0px 1px rgba($black, .04);
  border-radius: 10px;
  display grid
  grid-gap 15px

  &__header {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 0 15px;
    padding-top 15px
  }

  &__title {
    font-size 1.375em
    font-weight: 500;
    margin: 0;
  }

  &__close {
    background none
    padding 0
    border 0
    cursor pointer
    absolute right 15px top 15px

    .icon {
      width 28px
      height 28px

      svg {
        width 100%
        height 100%
      }
    }
  }

  &__footer
  &__buttons {
    display flex
    gap 5px
    padding 0 15px
  }

  &__buttons {
    padding-top 15px

    .btn {
      width 100%

      .icon {
        width 16px
        height 16px

        svg {
          width 100%
          height 100%
          svg($white)
        }
      }
    }
  }

  &__footer {
    padding-bottom 15px
  }
}
</style>
