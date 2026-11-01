<template>
  <label
    class="file-upload"
    :class="{
      'file-upload--disabled': disabled,
      'file-upload--highlight': highlight && !disabled,
    }"
  >
    <label v-if="title" class="file-upload__title">{{ title }}</label>
    <span
      class="file-upload__container"
      @dragenter="dragOver"
      @dragleave="dragLeave"
      @dragover="dragOver"
      @drop="drop"
    >
      <span v-if="!modelValue" class="file-upload__container-text">
        <IconComponent name="upload-line" />
        {{ text }}
      </span>
      <span
        v-if="modelValue && modelValue.length && !drag && !highlight && !loading"
        class="file-upload__list"
      >
        <span v-for="(file, i) in modelValue" :key="i" class="file-upload__item">
          <a href="#" type="button" class="file-upload__remove" @click.prevent="remove(i)">
            <IconComponent name="close" />
          </a>
          <a :href="file.url" class="file-upload__link" download target="_blank">
            <IconComponent category="files" :name="file.type" />
            <span>{{ file.origin_name }}</span>
          </a>
        </span>
      </span>
      <span v-else-if="modelValue && !drag && !highlight && !loading" class="file-upload__list">
        <span class="file-upload__item">
          <a href="#" type="button" class="file-upload__remove" @click.prevent="remove()">
            <IconComponent name="close-fill" />
          </a>
          <a :href="modelValue.url" class="file-upload__link" download target="_blank">
            <IconComponent category="files" :name="modelValue.type" />
            <span>{{ modelValue.origin_name }}</span>
          </a>
        </span>
      </span>
      <!--      <span v-else class="file-upload__placeholder">-->
      <!--        <IconComponent category="files" name="none" />-->
      <!--        <span class="file-upload__loading" v-if="loading">-->
      <!--          <IconComponent name="loading" category="default" />-->
      <!--          <span>Загрузка</span>-->
      <!--        </span>-->
      <!--        <template v-else>-->
      <!--          <span>Для загрузки файла перетащите его в данную область.</span>-->
      <!--          <span class="file-upload__or"><span></span><span>Или</span><span></span></span>-->
      <!--          <span class="btn btn&#45;&#45;small btn&#45;&#45;gray-outline&#45;&#45;thin btn&#45;&#45;gray-outline">-->
      <!--            Выбрать {{ multiple ? "файлы" : "файл" }}-->
      <!--          </span>-->
      <!--        </template>-->
      <!--      </span>-->
      <input
        v-show="false"
        ref="input"
        :disabled="loading || disabled"
        :multiple="multiple"
        type="file"
        @change="change"
      />
    </span>
  </label>
</template>

<script>
import FILE_UPLOAD from "@/graphql/mutations/FilesUpload.graphql";
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "FileUploadComponent",
  inject: ["apollo"],
  emits: ["update:model-value"],
  components: { IconComponent },
  props: {
    title: String,
    text: String,
    multiple: Boolean,
    path: String,
    modelValue: [Object, Array],
    disabled: Boolean,
  },
  data() {
    return {
      drag: false,
      highlight: false,
      over: false,
      loading: false,
      dragTimeout: null,
      highlightTimeout: null,
    };
  },
  mounted() {
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
        this.loading = true;
        this.debouncedDelayedSetOfHighlight(false);
        this.debouncedDelayedSetOfDrag(false);
        this.upload(dt.files);
      }
    },
    change(event) {
      if (!this.loading && !this.disabled) {
        this.loading = true;
        if (event.target.files.length) {
          this.upload(event.target.files);
        }
      }
    },
    remove(i) {
      if (confirm("Вы уверены?")) {
        if (i) {
          let files = JSON.parse(JSON.stringify(this.modelValue));
          files.splice(i, 1);
          if (!files.length) {
            files = null;
          }
          this.$emit("update:model-value", files);
        } else {
          this.$emit("update:model-value", null);
        }
      }
    },
    upload(files) {
      this.apollo()
        .admin.mutate({
          mutation: FILE_UPLOAD,
          variables: {
            files: files,
            path: this.path || undefined,
          },
        })
        .then(({ data }) => {
          let files = JSON.parse(JSON.stringify(this.modelValue));
          if (data.FilesUpload && data.FilesUpload.length) {
            if (this.multiple) {
              if (!files) {
                files = [];
              }
              files.push(...data.FilesUpload);
            } else {
              files = data.FilesUpload[0];
            }
          }
          this.loading = false;
          this.$emit("update:model-value", files);
        });
    },
  },
};
</script>

<style lang="stylus">
.file-upload {
  display grid
  grid-gap 5px

  &__container {
    display flex
    align-items center
    justify-content center
    flex-flow row wrap
    background var(--gray-3)
    border: 1px dashed var(--white-2);
    border-radius 10px
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
    justify-content center
    gap: 24px;
  }

  &__loading {
    position absolute
    left 0
    top 0
    width 100%
    height 100%
    display flex
    justify-content center
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
          fill var(--white)
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
    border-radius 100%
    width 20px
    height 20px
    display inline-flex
    align-items center
    background var(--red)
    border none
    justify-content center
    position absolute
    right 0
    top 0
    z-index 1
    cursor pointer

    .icon {
      width 20px
      height 20px

      path {
        fill var(--white)
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
