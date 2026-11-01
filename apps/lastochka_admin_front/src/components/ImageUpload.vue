<template>
  <div class="image-upload__container">
    <span class="image-upload__title" v-if="title">{{ title }}</span>
    <label
      :class="{
        'image-upload--disabled': disabled,
        'image-upload--highlight': highlight && !disabled,
        // 'image-upload--loading': loading,
      }"
      class="image-upload"
      @dragenter="dragOver"
      @dragleave="dragLeave"
      @dragover="dragOver"
      @drop="drop"
    >
      <img v-if="!modelValue && files" :src="imageUpload(files, this.media_endpoint)" alt="" />
      <span v-if="modelValue" class="image-upload__image">
        <img :src="this.media_endpoint + modelValue.path" alt="" />
        <span class="image-upload__remove" @click.prevent="clear">
          <IconComponent name="delete-bin-line" />
        </span>
      </span>
      <template v-else>
        <!--        <span class="image-upload__loading" v-if="loading">-->
        <!--          <span class="image-upload__loading-progress" :style="`width: ${progress}%`"></span>-->
        <!--        </span>-->
        <span class="image-upload__container-text">
          <IconComponent name="upload-line" />
          {{ text }}
          <span class="image-upload__remove" @click.prevent="removeImg">
            <IconComponent name="delete-bin-line" />
          </span>
        </span>
      </template>
      <input v-show="false" :accept="accept" ref="input" :disabled="disabled" type="file" @change="change" />
    </label>
    <span class="image-upload__error" v-if="readError">{{ readError }}</span>
  </div>
</template>

<script>
// import FILES_UPLOAD from "@/graphql/mutations/FilesUpload.graphql";
import IconComponent from "@/components/IconComponent.vue";
import CropImageModal from "@/components/modals/components/CropImageModal.vue";
import { imageUpload } from "@/utils.js";
import { useDefaultStore } from "@/stores/default.js";
import { shallowRef } from "vue";
export default {
  inject: ["apollo"],
  name: "ImageUploadComponent",
  emits: ["update:model-value", "removeItem", "uploadImage"],
  components: { IconComponent },
  props: {
    uploaded: Object,
    disabled: Boolean,
    title: String,
    text: String,
    modelValue: [String, Number, Object],
    accept: {
      type: String,
      default: "image/*",
    },
  },
  data() {
    return {
      progress: 0,
      drag: false,
      highlight: false,
      over: false,
      // loading: false,
      videoTitle: null,
      files: null,
      dragTimeout: null,
      highlightTimeout: null,
      image: null,
      readError: null,
      regexp: {
        image: /image\/*/,
        video: /video\/*/,
      },
    };
  },
  watch: {
    uploaded() {
      if (this.uploaded) {
        this.videoTitle = this.uploaded.title;
        this.files = this.uploaded;
      }
    },
  },
  mounted() {
    if (this.uploaded) {
      this.videoTitle = this.uploaded.title;
      this.files = this.uploaded;
    }
    document.addEventListener("dragover", (event) => {
      event.preventDefault();
      event.stopPropagation();
    });
    document.addEventListener("dragover", (event) => {
      event.preventDefault();
      event.stopPropagation();
      this.drag = true;
    });
    document.addEventListener("dragleave", (event) => {
      event.preventDefault();
      event.stopPropagation();
      this.debouncedDelayedSetOfDrag(false);
    });
  },
  methods: {
    imageUpload,
    clear() {
      if (confirm("Вы уверены?")) {
        this.files = null;
        this.$emit("update:model-value", this.files);
      }
    },
    removeImg() {
      this.$emit("removeItem");
    },
    debouncedDelayedSetOfDrag(value) {
      clearTimeout(this.dragTimeout);
      this.dragTimeout = setTimeout(() => {
        this.drag = value;
      }, 200);
    },
    debouncedDelayedSetOfHighlight(value) {
      clearTimeout(this.highlightTimeout);
      this.highlightTimeout = setTimeout(() => {
        this.highlight = value;
      }, 200);
    },
    dragLeave(event) {
      event.preventDefault();
      event.stopPropagation();
      this.debouncedDelayedSetOfHighlight(false);
    },
    dragOver(event) {
      event.preventDefault();
      event.stopPropagation();
      this.highlight = true;
    },
    drop(event) {
      event.preventDefault();
      const dt = event.dataTransfer;
      if (dt) {
        // this.loading = true;
        this.debouncedDelayedSetOfHighlight(false);
        this.debouncedDelayedSetOfDrag(false);
        this.upload(dt.files);
      }
    },
    change(event) {
      if (!this.disabled) {
        // this.loading = true;
        if (event.target.files.length) {
          this.upload(event.target.files);
        }
        // Сбрасываем значение, иначе повторный выбор ТОГО ЖЕ файла не даст change
        // и подсказка «выберите файл заново» перестаёт работать.
        event.target.value = "";
      }
    },
    upload(files) {
      if (files.length) {
        // Забираем File сразу: FileList живой и опустеет после сброса input.value
        const file = files[0];
        const reader = new FileReader();
        this.readError = null;
        // onerror + onload вместо onloadend: onloadend стреляет и при ошибке
        // чтения, где reader.result === null, и модалка открывалась пустой.
        reader.onerror = () => {
          this.readError = "Не удалось прочитать файл. Попробуйте выбрать его ещё раз.";
        };
        reader.onload = () => {
          if (!reader.result) {
            this.readError = "Не удалось прочитать файл. Попробуйте выбрать его ещё раз.";
            return;
          }
          useDefaultStore().modals.push({
            component: shallowRef(CropImageModal),
            options: {
              data: {
                imageBase64: reader.result,
                image: file,
              },
              callback: (res) => {
                let localValue = {
                  path: res.path,
                  origin_name: res.origin_name,
                  alt: res.alt,
                  size: res.size,
                  type: res.type,
                };
                useDefaultStore().modal_img = localValue;
                this.$emit("update:model-value", localValue);
                this.$emit("uploadImage");
              },
            },
          });
        };
        reader.readAsDataURL(file);
      }

      // if (files && files.length) {
      //   // let reader = new FileReader();
      //   // reader.readAsDataURL(files[0]);
      //   // reader.onload = (e) => {
      //   //   this.image = e.target.result;
      //   // };
      //   this.apollo()
      //     .admin.mutate({
      //       mutation: FILES_UPLOAD,
      //       variables: {
      //         files: files,
      //       },
      //       context: {
      //         fetchOptions: {
      //           useUpload: true,
      //           onProgress: (e) => {
      //             this.progress = (e.loaded / e.total) * 100;
      //           },
      //           onAbortPossible: () => {},
      //         },
      //       },
      //     })
      //     .then(({ data }) => {
      //       const reader = new FileReader();
      //       if (data.FilesUpload && data.FilesUpload.length) {
      //         this.files = data.FilesUpload[0];
      //         console.log("reader", reader);
      //         reader.onloadend = () => {
      //           console.log("reader result", reader.result);
      //         };
      //         console.log("asd", reader.result);
      //       }
      //
      //       this.loading = false;
      //       this.$emit("update:model-value", this.files);
      //       this.$emit("uploadImage");
      //     })
      //     .catch(() => {
      //       this.loading = false;
      //     });
      // }
    },
  },
  computed: {
    media_endpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
  },
};
</script>

<style lang="stylus">
//@import "~@/styles/mixins/svg.styl"
@keyframes stripped {
  0% {
    background-position: 0 0;
  }

  100% {
    background-position: 31px 62px;
  }
}

.image-upload {
  gap 5px
  cursor pointer
  display flex
  align-items center
  justify-content center
  width 100%
  height 100%

  & img {
    width 100%
    height 100%
    object-fit contain
  }

  &__image {
    width 100%
    height 100%
  }

  &__container {
    display flex
    align-items center
    justify-content center
    flex-direction column-reverse
    background var(--gray-3)
    border: 2px dashed var(--white-2);
    border-radius 10px
    overflow hidden
    //padding 38px 25px
    gap 24px
    transition border-color .2s
    cursor pointer
    position relative
    height: 169px;
    width: 169px;

    ^[0]--highlight &,
    &:hover {
      //padding 37px 24px
      border-width 2px
      border-color var(--main);

      .image-upload__remove {
        opacity 1
        visibility visible
      }
    }

    ^[0]--highlight & &__btn,
    &:hover &__btn {
      background var(--white)
      border-color var(--main)
      color var(--main)
    }

    ^[0]:active &__btn {
      transform scale(0.97)
    }

    &-text {
      display flex
      flex-direction column
      gap 5px
      font-weight: 500;
      font-size: 0.8125em;
      line-height: 140%;
      text-align: center;
      color var(--gray-7)
      svg {
        max-width 24px

        path {
          fill var(--gray-7)
        }
      }
    }
  }

  // Сообщение о нечитаемом файле — оверлеем по центру плитки: контейнер имеет
  // фиксированные 169x169 и overflow hidden, поток раздувать нельзя. Центрируем,
  // а не растягиваем на всю высоту, иначе фон накрывает подпись снизу.
  // pointer-events none — клик проходит на label, файл можно выбрать заново.
  &__error {
    position absolute
    left 0
    right 0
    top 50%
    transform translateY(-50%)
    box-sizing border-box
    padding 8px
    display block
    text-align center
    background var(--gray-3)
    pointer-events none
    font-weight: 500;
    font-size: 0.75em;
    line-height: 140%;
    color var(--red)
    z-index 2
  }

  &__title {
    font-weight: 700;
    font-size: 0.865rem;
    line-height: 24px;
    color: var(--dark);
    font-family var(--font-regular)
    display block
  }

  &__or {
    width 100%
    display flex
    align-items center
    justify-content center
    gap 15px
    font-size: 0.875em;
    line-height: 17px;
    text-align: center;
    color: var(--black);

    span:last-child,
    span:first-child {
      max-width 100px
      width 100%
      height 1px
      background var(--main)
    }
  }

  &__list {
    width: 100%;
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 24px;
  }

  &__loading-progress {
    background-size: 30px 30px;
    background-image: linear-gradient(-45deg, #00000040 25%, #0000 25%, #0000 50%, #00000040 50%, #00000040 75%, #0000 75%, #0000);
    animation: stripped 2s linear 0s infinite normal none running;
    height 100%
  }

  &__loading {
    position absolute
    left 0
    top 0
    width 100%
    height 100%
    display flex
    justify-content flex-start
    align-items center
    background var(--dark-light);
    border-radius 10px
    color var(--white)

    .icon {
      width 40px
      height 40px

      svg {
        width 100%
        height 100%

        path {
          fill var(--main)
        }
      }
    }
  }

  &__item {
    width 92px
    position relative

    span {
      width 100%
      color: var(--dark-light);
    }

    .icon {
      height 60px
      max-width 60px
    }
  }

  &__link {
    width 100%
    display inline-flex
    flex-direction column
    justify-content flex-start
    gap 12px
    align-items center
    font-weight: normal;
    font-size: 0.75em;
    line-height: normal;
    text-align: center;
    color: $dark;
    white-space: pre-wrap;
    word-wrap: break-word;
  }

  &__remove {
    opacity 0
    visibility hidden
    border-radius 8px
    padding: 8px
    display inline-flex
    align-items center
    background var(--red-light)
    border none
    justify-content center
    position absolute
    right 5px
    top 5px
    z-index 1
    cursor pointer
    transition var(--transition)

    .icon {
      width 16px
      height 16px

      path {
        fill var(--red)
      }
    }
  }

  &__placeholder {
    display flex
    flex-direction column
    align-items center
    justify-content center
    gap 8px
    font-weight: normal;
    font-size: 0.8750em;
    line-height: 20px;
    text-align: center;
    color: var(--dark-light);
    user-select none

    .icon {
      height 60px
      max-width 60px
    }
  }
}
</style>
