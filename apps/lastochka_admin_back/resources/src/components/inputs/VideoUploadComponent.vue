<template>
  <div class="video-upload__container">
    <span class="video-upload__title">{{ title }}</span>
    <label
      v-if="!files || files.delete"
      :class="{
        'video-upload--disabled': disabled,
        'video-upload--highlight': highlight && !disabled,
      }"
      class="video-upload"
      @dragenter="dragOver"
      @dragleave="dragLeave"
      @dragover="dragOver"
      @drop="drop"
    >
      <span class="video-upload__loading" v-if="loading">
        <span class="video-upload__loading-progress" :style="`width: ${progress}%`"></span>
        <LoadingIndicator title="Пожалуйста дождитесь загрузки" />
      </span>
      <span class="video-upload__placeholder" v-else>
        <template>
          <span>Для загрузки видео перетащите его в данную область.</span>
          <span class="video-upload__or"><span></span><span>Или</span><span></span></span>
          <span class="btn btn--small btn--main-outline">Выбрать видео</span>
        </template>
      </span>
      <input
        v-show="false"
        accept="video/*"
        ref="input"
        :disabled="loading || disabled"
        type="file"
        @change="change"
      />
    </label>
    <div class="video-upload__input" v-if="files && !files.delete">
      <span v-if="files.rendered">Видео "{{ files.origin_name }}" обработано и готово к просмотру</span>
      <span v-else>Видео "{{ files.origin_name }}" загружено и обрабатывается</span>
      <InputComponent
        v-model.trim="videoTitle"
        @input="emitFilesWithTitle"
        autofocus
        title="Название видео"
      />
      <button class="btn btn--medium btn--red" type="button" @click="clear">Очистить</button>
    </div>
  </div>
</template>

<script>
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import VIDEO_UPLOAD from "@/graphql/mutations/VideoUpload.graphql";
import InputComponent from "./InputComponent.vue";

export default {
  name: "VideoUploadComponent",
  props: {
    uploaded: Object,
    disabled: Boolean,
    title: String,
  },
  data() {
    return {
      progress: 0,
      drag: false,
      highlight: false,
      over: false,
      loading: false,
      videoTitle: null,
      files: null,
      dragTimeout: null,
      highlightTimeout: null,
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
    clear() {
      if (confirm("Вы уверены?")) {
        this.files.delete = true;
        this.videoTitle = null;
        this.emitFilesWithTitle();
      }
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
    emitFilesWithTitle() {
      this.files.title = this.videoTitle;
      this.$emit("change", this.files);
    },
    upload(files) {
      this.$apollo
        .mutate({
          mutation: VIDEO_UPLOAD,
          variables: {
            video: files[0],
            title: this.videoTitle,
          },
          context: {
            fetchOptions: {
              useUpload: true,
              onProgress: (e) => {
                this.progress = (e.loaded / e.total) * 100;
              },
              onAbortPossible: () => {},
            },
          },
        })
        .then(({ data }) => {
          if (data.VideoUpload) {
            this.files = Object.assign(
              {
                delete: false,
              },
              data.VideoUpload
            );
          }
          this.loading = false;
          this.emitFilesWithTitle();
        });
    },
  },
  components: {
    InputComponent,
    LoadingIndicator,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
@import "~@/styles/mixins/svg.styl"
@keyframes stripped {
  0% {
    background-position: 0 0;
  }

  100% {
    background-position: 31px 62px;
  }
}

.video-upload {
  display flex
  align-items center
  justify-content center
  flex-flow row wrap
  width 100%
  min-height 200px
  background $white
  border: 1px solid $gray1;
  border-radius 10px
  padding 38px 25px
  gap 24px
  transition border-color .2s
  cursor pointer
  position relative

  &__title {
    font-size: 0.875em;
    display: block;
    line-height: 1.5em;
    color: $gray3;
  }

  &__container {
    display grid
    align-items start
    justify-content stretch
    grid-gap 5px
  }

  &__input {
    display flex
    flex-direction column
    align-items flex-start
    justify-content flex-start
    gap 10px
  }

  &__loading {
    position relative
    display flex
    align-items center
    justify-content center
    border-radius 10px
    absolute left top
    width 100%
    font-size 18px
    text-shadow 0 0 2px $gray1
    height 100%
    overflow hidden

    &-progress {
      absolute left top
      height 100%
      transition width .2s
      background-color rgba($main_color, .1);
      background-size: 31px 31px;
      background-image: linear-gradient(-45deg, rgba(0, 0, 0, .1) 25%, rgba(0, 0, 0, 0) 0, rgba(0, 0, 0, 0) 50%, rgba(0, 0, 0, .1) 0, rgba(0, 0, 0, .1) 75%, rgba(0, 0, 0, 0) 0, rgba(0, 0, 0, 0));
      animation: stripped 2s linear 0s infinite normal none running;
    }
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
    color: $black

    span:last-child,
    span:first-child {
      max-width 100px
      width 100%
      height 1px
      background $gray1
    }
  }

  &--highlight &__btn
  &:hover &__btn {
    background $white
    border-color $main_color
    color $main_color
  }

  &:active &__btn {
    transform scale(0.97)
  }

  &__list {
    width: 100%;
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 24px;
  }

  &__item {
    width 92px
    display flex
    flex-direction column
    justify-content flex-start
    align-items center
    font-weight: normal;
    font-size: 0.75em;
    line-height: normal;
    text-align: center;
    color: $gray1;
    white-space: pre-wrap;
    word-wrap: break-word;
    gap 12px
    position relative
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
  }
}
</style>
