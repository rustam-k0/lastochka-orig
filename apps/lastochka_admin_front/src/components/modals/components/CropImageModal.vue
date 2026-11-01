<template>
  <div class="cropper">
    <div class="cropper__header">
      <h3 class="cropper__title">Обрезка изображения</h3>
      <button v-if="!loading" class="cropper__close" type="button" @click="$emit('close')">
        <!--        <IconComponent name="close" />-->
      </button>
    </div>
    <div class="cropper__body">
      <div class="cropper__placeholder" v-if="!data.imageBase64">
        Не удалось прочитать файл изображения. Закройте окно и выберите файл заново.
      </div>
      <div v-else>
        <VueCropper
          ref="cropper"
          :src="data.imageBase64"
          :viewMode="1"
          @ready="fitImage"
          alt="Изображение"
          class="cropper__container"
        />
        <div v-if="!loading" class="cropper__buttons">
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(10, 0)">
            <IconComponent name="arrow-left-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(0, 10)">
            <IconComponent name="arrow-up-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(0, -10)">
            <IconComponent name="arrow-down-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.move(-10, 0)">
            <IconComponent name="arrow-right-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.rotate(-45)">
            <IconComponent name="anticlockwise-2-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.rotate(45)">
            <IconComponent name="clockwise-2-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="scaleHorizontal">
            <IconComponent name="arrow-left-right-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="scaleVertical">
            <IconComponent name="arrow-up-down-fill" />
          </button>
          <button
            class="btn btn--small btn--main-outline"
            type="button"
            @click="$refs.cropper.relativeZoom(0.1)"
          >
            <IconComponent name="zoom-in-line" />
          </button>
          <button
            class="btn btn--small btn--main-outline"
            type="button"
            @click="$refs.cropper.relativeZoom(-0.1)"
          >
            <IconComponent name="zoom-out-line" />
          </button>
          <button class="btn btn--small btn--main-outline" type="button" @click="$refs.cropper.reset()">
            <IconComponent name="refresh-line" />
          </button>
        </div>
      </div>
    </div>
    <div class="cropper__footer">
      <div class="input__errors" v-if="errors.length">
        <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
      </div>
      <button
        v-if="data.imageBase64"
        class="btn btn--main btn--medium"
        :class="{ 'btn--loading': saving }"
        type="button"
        @click="cropImage"
      >
        {{ saving ? "Обработка" : "Обрезать" }}
      </button>
    </div>
  </div>
</template>

<script>
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import IMAGE_UPLOAD_CROP from "@/graphql/mutations/ImageUploadCrop.graphql";
import IconComponent from "@/components/IconComponent.vue";
import VueCropper from "vue-cropperjs/VueCropper.js";
import "cropperjs/dist/cropper.css";

export default {
  name: "CropImageModal",
  inject: ["apollo"],
  props: {
    data: Object,
    callback: Function,
  },
  components: {
    IconComponent,
    VueCropper,
  },
  data() {
    return {
      loading: false,
      saving: false,
      scaleH: -1,
      scaleV: -1,
      errors: [],
    };
  },
  methods: {
    /**
     * Вписать изображение в область кропа после инициализации cropper.
     *
     * Событие ready прилетает асинхронно, уже после загрузки картинки, и
     * пользователь успевает закрыть модалку раньше: тогда $refs.cropper пуст,
     * и прямой вызов zoomTo падал с «Cannot read properties of null».
     */
    fitImage() {
      this.$refs.cropper?.zoomTo(0);
    },
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
    /**
     * Отправить обрезанное изображение на сервер.
     *
     * Cropper может отсутствовать: картинка не прочиталась (imageBase64 пуст)
     * либо модалку успели закрыть. Выходим ДО выставления loading, иначе
     * крестик закрытия (v-if="!loading") пропадает и окно уже не закрыть.
     */
    cropImage() {
      if (!this.loading) {
        const cropper = this.$refs.cropper;
        if (!cropper) {
          this.errors = ["Изображение не загрузилось. Закройте окно и выберите файл заново."];
          return;
        }
        this.saving = true;
        this.loading = true;
        this.errors = [];
        let variables = {
          image: this.data.image,
        };
        let crop = cropper.getData();
        Object.keys(crop).forEach((key) => {
          variables[key] = parseInt(crop[key]);
        });
        this.apollo()
          .admin.mutate({
            mutation: IMAGE_UPLOAD_CROP,
            variables: variables,
          })
          .then(({ data }) => {
            if (data.ImageUploadCrop) {
              let res = data.ImageUploadCrop;
              res.alt = null;
              this.callback(res);
            }
            this.saving = false;
            this.loading = false;
            this.close();
          })
          .catch((error) => {
            this.errors = this.parseErrors(error);
            this.saving = false;
            this.loading = false;
          });
      }
    },
    /**
     * Тексты ошибок мутации для показа в модалке
     */
    parseErrors(error) {
      let messages = [];
      (error.graphQLErrors || []).forEach((err) => {
        if (err.extensions && err.extensions.validation) {
          Object.values(err.extensions.validation).forEach((list) => {
            messages.push(...list);
          });
        } else if (err.message) {
          messages.push(err.message);
        }
      });
      if (!messages.length) {
        messages.push("Не удалось загрузить изображение. Попробуйте ещё раз.");
      }
      return messages;
    },
  },
};
</script>
<style lang="stylus">
//@import "~@/styles/laravel-mix-fix.styl"
.cropper {
  max-width 626px
  width 100%
  z-index 2
  background: var(--white);
  //box-shadow: 0 16px 24px rgba($black, .06), 0px 2px 6px rgba($black, .04), 0px 0px 1px rgba($black, .04);
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
          //svg($white)
        }
      }
    }
  }

  &__footer {
    padding-bottom 15px
  }

  &__placeholder {
    padding 0 15px
    font-size: 0.875em;
    line-height: 20px;
    color: var(--dark-light);
  }
}
</style>
