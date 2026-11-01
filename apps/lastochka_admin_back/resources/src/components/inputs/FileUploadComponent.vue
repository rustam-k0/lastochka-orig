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
      <span v-if="files && files.length && !drag && !highlight" class="file-upload__list">
        <span v-for="(file, i) in files" :key="i" class="file-upload__item">
          <a href="#" type="button" class="file-upload__remove" @click.prevent="remove(i)">
            <IconComponent category="system" name="close-line" />
          </a>
          <a :href="file.url" class="file-upload__link" download target="_blank">
            <!--<span class="file-upload__loading">-->
            <!--  <LoaderIcon class="loading-rotate" />-->
            <!--</span>-->
            <IconComponent collection="files" category="default" :name="file.type" />
            <span>{{ file.origin_name }}</span>
          </a>
        </span>
      </span>
      <span v-else class="file-upload__placeholder">
        <IconComponent collection="files" category="default" name="none" />
        <LoadingIndicator v-if="loading" title="Загрузка файлов" />
        <template v-else>
          <span>Для загрузки файла перетащите его в данную область.</span>
          <span class="file-upload__or"><span></span><span>Или</span><span></span></span>
          <span class="btn btn--small btn--gray-outline--thin btn--gray-outline">Выбрать файлы</span>
        </template>
      </span>
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
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import IconComponent from "../IconComponent.vue";

export default {
  name: "FileUploadComponent",
  props: {
    title: String,
    multiple: Boolean,
    path: String,
    uploaded: Array,
    disabled: Boolean,
  },
  data() {
    return {
      drag: false,
      highlight: false,
      over: false,
      loading: false,
      files: [],
      dragTimeout: null,
      highlightTimeout: null,
    };
  },
  watch: {
    uploaded() {
      this.files = this.uploaded;
    },
  },
  mounted() {
    this.files = this.uploaded;
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
      if (!this.loading) {
        if (confirm("Вы уверены?")) {
          this.loading = true;
          this.files.splice(i, 1);
          this.$emit("change", this.files);
          this.loading = false;
        }
      }
    },
    upload(files) {
      this.$apollo
        .mutate({
          mutation: FILE_UPLOAD,
          variables: {
            files: files,
            path: this.path || undefined,
          },
        })
        .then(({ data }) => {
          if (data.FilesUpload && data.FilesUpload.length) {
            if (this.multiple) {
              this.files.push(...data.FilesUpload);
            } else {
              this.files = data.FilesUpload;
            }
          }
          this.loading = false;
          this.$emit("change", this.files);
        });
    },
  },
  components: {
    IconComponent,
    LoadingIndicator,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.file-upload {
  display grid
  grid-gap 5px

  &__container {
    display flex
    align-items center
    justify-content center
    flex-flow row wrap
    width 100%
    min-height 200px
    background $gray
    border: 1px dashed $main_color;
    border-radius 10px
    padding 38px 25px
    gap 24px
    transition border-color $transition
    cursor pointer

    ^[0]--highlight &,
    &:hover {
      padding 37px 24px
      border-width 2px
      border-color $main_color;
    }

    ^[0]--highlight & &__btn,
    &:hover &__btn {
      background $white
      border-color $main_color
      color $main_color
    }

    ^[0]:active &__btn {
      transform scale(0.97)
    }
  }

  &__title {
    font-size 0.875em
    font-family $font_regular
    display block
    line-height 1.5em
    color $gray3
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
    color: $black;

    span:last-child,
    span:first-child {
      max-width 100px
      width 100%
      height 1px
      background $main_color
    }
  }

  &__list {
    width: 100%;
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 24px;
  }

  &__loading {
    absolute left top
    width 100%
    height 100%
    display flex
    justify-content center
    align-items center
    background $dark;
    border-radius 10px

    .icon {
      width 40px
      height 40px

      svg {
        width 100%
        height 100%

        path {
          fill $white
        }
      }
    }
  }

  &__item {
    width 92px
    position relative

    span {
      width 100%
      color: $dark;
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
    background $red
    border none
    justify-content center
    absolute right top
    z-index 1
    cursor pointer

    .icon {
      width 20px
      height 20px

      path {
        fill $white
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
    color: $dark;
    user-select none

    .icon {
      height 60px
      max-width 60px
    }
  }
}
</style>
