<template>
  <div class="story-image-uploader">
    <div class="story-image-uploader__label">{{ title }} <span class="required">*</span></div>
    <div class="story-image-uploader__container">
      <div class="story-image-uploader__list">
        <!-- Загруженные изображения с drag-and-drop сортировкой -->
        <div 
          v-for="(image, index) in modelValue" 
          :key="image.id || index"
          class="story-image-uploader__item"
          draggable="true"
          @dragstart="onDragStart(index, $event)"
          @dragover.prevent="onDragOver(index)"
          @drop.prevent="onDrop(index)"
          @dragend="onDragEnd"
        >
          <img :src="getImageSrc(image)" :alt="image.alt || ''" />
          <div class="story-image-uploader__item-overlay">
            <span class="story-image-uploader__item-number">{{ index + 1 }}</span>
            <button 
              type="button" 
              class="story-image-uploader__item-remove"
              @click="removeImage(index)"
            >
              <IconComponent name="delete-bin-line" />
            </button>
          </div>
        </div>
        
        <!-- Кнопка добавления нового изображения -->
        <label class="story-image-uploader__add">
          <IconComponent name="add-line" />
          <span>Добавить</span>
          <input 
            type="file" 
            ref="fileInput"
            accept="image/*"
            @change="onChange"
          />
        </label>
      </div>
    </div>
    <span v-if="error" class="story-image-uploader__error">{{ error }}</span>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import CropImageModal from "@/components/modals/components/CropImageModal.vue";
import { useDefaultStore } from "@/stores/default.js";
import { shallowRef } from "vue";

export default {
  name: "StoryImageUploader",
  components: { IconComponent },
  props: {
    title: {
      type: String,
      default: "Изображения",
    },
    modelValue: {
      type: Array,
      default: () => [],
    },
    minImages: {
      type: Number,
      default: 1,
    },
  },
  emits: ["update:modelValue"],
  data() {
    return {
      draggedIndex: null,
      readError: "",
    };
  },
  computed: {
    media_endpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
    error() {
      if (this.readError) {
        return this.readError;
      }
      if (this.minImages > 0 && (!this.modelValue || this.modelValue.length < this.minImages)) {
        return `Минимум ${this.minImages} изображений`;
      }
      return "";
    },
  },
  methods: {
    getImageSrc(image) {
      if (image.path && image.path.startsWith("data:")) {
        return image.path;
      }
      return this.media_endpoint + image.path;
    },
    onChange(event) {
      const file = event.target.files?.[0];
      if (!file || !file.type.startsWith("image/")) {
        event.target.value = "";
        return;
      }
      
      const reader = new FileReader();
      this.readError = "";
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
              const newImage = {
                path: res.path,
                origin_name: res.origin_name,
                alt: res.alt,
                size: res.size,
                type: res.type,
              };
              const currentImages = [...(this.modelValue || [])];
              currentImages.push(newImage);
              this.$emit("update:modelValue", currentImages);
            },
          },
        });
      };
      reader.readAsDataURL(file);
      event.target.value = "";
    },
    removeImage(index) {
      if (confirm("Удалить изображение?")) {
        const newImages = [...this.modelValue];
        newImages.splice(index, 1);
        this.$emit("update:modelValue", newImages);
      }
    },
    onDragStart(index, event) {
      this.draggedIndex = index;
      event.dataTransfer.effectAllowed = "move";
    },
    onDragOver(index) {
      if (this.draggedIndex === null || this.draggedIndex === index) return;
    },
    onDrop(targetIndex) {
      if (this.draggedIndex === null || this.draggedIndex === targetIndex) return;
      
      const newImages = [...this.modelValue];
      const [draggedItem] = newImages.splice(this.draggedIndex, 1);
      newImages.splice(targetIndex, 0, draggedItem);
      this.$emit("update:modelValue", newImages);
      this.draggedIndex = null;
    },
    onDragEnd() {
      this.draggedIndex = null;
    },
  },
};
</script>

<style lang="stylus">
.story-image-uploader {
  &__label {
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    margin-bottom 12px
    
    .required {
      color var(--red)
    }
  }
  
  &__container {
    border 2px dashed var(--gray-dark)
    border-radius 12px
    padding 16px
    min-height 200px
  }
  
  &__list {
    display grid
    grid-template-columns repeat(auto-fill, minmax(120px, 1fr))
    gap 12px
  }
  
  &__item {
    position relative
    aspect-ratio 9 / 16
    border-radius 8px
    overflow hidden
    cursor grab
    
    &:active {
      cursor grabbing
    }
    
    img {
      width 100%
      height 100%
      object-fit cover
    }
  }
  
  &__item-overlay {
    position absolute
    top 0
    left 0
    right 0
    bottom 0
    background linear-gradient(180deg, rgba(0,0,0,0.3) 0%, transparent 50%, rgba(0,0,0,0.3) 100%)
    opacity 0
    transition opacity 0.2s
    display flex
    flex-direction column
    justify-content space-between
    padding 8px
    
    ^[0]__item:hover & {
      opacity 1
    }
  }
  
  &__item-number {
    background var(--white)
    color var(--dark)
    width 24px
    height 24px
    border-radius 50%
    display flex
    align-items center
    justify-content center
    font-size 0.75rem
    font-weight 600
  }
  
  &__item-remove {
    background var(--red)
    border none
    width 28px
    height 28px
    border-radius 6px
    display flex
    align-items center
    justify-content center
    cursor pointer
    align-self flex-end
    transition transform 0.2s
    
    &:hover {
      transform scale(1.1)
    }
    
    .icon {
      width 16px
      height 16px
      
      svg path {
        fill var(--white)
      }
    }
  }
  
  &__add {
    display flex
    flex-direction column
    align-items center
    justify-content center
    aspect-ratio 9 / 16
    border 2px dashed var(--gray-dark)
    border-radius 8px
    cursor pointer
    transition all 0.2s
    gap 8px
    
    input {
      display none
    }
    
    .icon {
      width 32px
      height 32px
      
      svg path {
        fill var(--dark-light)
      }
    }
    
    span {
      font-size 0.75rem
      color var(--dark-light)
    }
    
    &:hover {
      border-color var(--pink)
      
      .icon svg path {
        fill var(--pink)
      }
      
      span {
        color var(--pink)
      }
    }
  }
  
  &__error {
    display block
    margin-top 8px
    font-size 0.75rem
    color var(--red)
  }
}
</style>
