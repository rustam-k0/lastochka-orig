<template>
  <form class="story-form" @submit.prevent="submit">
    <div class="story-form__grid">
      <!-- Левая колонка -->
      <div class="story-form__main">
        <!-- Название -->
        <div class="story-form__field">
          <label class="story-form__label">Название <span class="required">*</span></label>
          <input 
            type="text"
            v-model="form.title"
            placeholder="Введите название истории"
            class="story-form__input"
            :class="{ 'story-form__input--error': errors.title }"
            maxlength="255"
          />
          <span v-if="errors.title" class="story-form__error">{{ errors.title }}</span>
        </div>

        <!-- Дата окончания -->
        <div class="story-form__field">
          <label class="story-form__label">Дата окончания <span class="required">*</span></label>
          <DateInput
            v-model="form.expires_at"
            :mode="'dateTime'"
            placeholder="Выберите дату и время"
          />
          <span v-if="errors.expires_at" class="story-form__error">{{ errors.expires_at }}</span>
        </div>

        <!-- Тип перехода -->
        <StoryLinkTypeSelector
          v-model="form.link_type"
          v-model:productId="form.product_id"
          v-model:shopId="form.shop_id"
          v-model:externalUrl="form.external_url"
          :initialProduct="initialData?.product"
          :initialShop="initialData?.shop"
        />

        <!-- Активность -->
        <div class="story-form__field story-form__field--toggle">
          <label class="story-form__label">Активность</label>
          <label class="story-form__toggle">
            <input type="checkbox" v-model="form.is_active" />
            <span class="story-form__toggle-slider"></span>
            <span class="story-form__toggle-text">{{ form.is_active ? 'Активна' : 'Неактивна' }}</span>
          </label>
        </div>

        <!-- Быстрая категория -->
        <div v-if="checkboxStore.isEnabled('Story', 'is_quick_category')" class="story-form__field story-form__field--toggle">
          <label class="story-form__label">{{ checkboxStore.getLabel('Story', 'is_quick_category') }}</label>
          <label class="story-form__toggle">
            <input type="checkbox" v-model="form.is_quick_category" />
            <span class="story-form__toggle-slider"></span>
            <span class="story-form__toggle-text">{{ form.is_quick_category ? 'Да' : 'Нет' }}</span>
          </label>
        </div>

        <div class="story-form__field">
          <CustomCheckboxesField entity="Story" v-model="form.custom_checkboxes" />
        </div>
      </div>

      <!-- Правая колонка - изображения -->
      <div class="story-form__media">
        <!-- Превью изображение -->
        <div class="story-form__field">
          <label class="story-form__label">Превью изображение <span class="required">*</span></label>
          <div class="story-form__thumbnail">
            <ImageUpload
              v-model="form.thumbnailImage"
              text="Загрузить превью"
              @uploadImage="onThumbnailUpload"
            />
          </div>
          <span v-if="errors.thumbnailImage" class="story-form__error">{{ errors.thumbnailImage }}</span>
        </div>

        <!-- Изображения слайдера -->
        <StoryImageUploader
          title="Изображения слайдера"
          v-model="form.images"
          :multiple="true"
          :minImages="1"
        />
        <span v-if="errors.images" class="story-form__error">{{ errors.images }}</span>
      </div>
    </div>

    <!-- Кнопки -->
    <div class="story-form__actions">
      <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">
        Отмена
      </button>
      <button type="submit" class="btn btn--md" :disabled="loading">
        <span v-if="loading">Сохранение...</span>
        <span v-else>{{ isEdit ? 'Сохранить' : 'Создать' }}</span>
      </button>
    </div>
  </form>
</template>

<script>
import DateInput from "@/components/inputs/DateInput.vue";
import ImageUpload from "@/components/ImageUpload.vue";
import StoryLinkTypeSelector from "./StoryLinkTypeSelector.vue";
import StoryImageUploader from "./StoryImageUploader.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import { parseCustomCheckboxes } from "@/utils.js";
import dayjs from "dayjs";

export default {
  name: "StoryForm",
  components: {
    DateInput,
    ImageUpload,
    StoryLinkTypeSelector,
    StoryImageUploader,
    CustomCheckboxesField,
  },
  props: {
    initialData: {
      type: Object,
      default: null,
    },
    loading: {
      type: Boolean,
      default: false,
    },
    isEdit: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["submit", "cancel"],
  computed: {
    checkboxStore() {
      return useCheckboxConfigStore();
    },
  },
  data() {
    return {
      form: {
        title: "",
        expires_at: null,
        link_type: "NONE",
        product_id: null,
        shop_id: null,
        external_url: "",
        is_active: true,
        is_quick_category: false,
        custom_checkboxes: {},
        thumbnailImage: null,
        images: [],
      },
      errors: {},
    };
  },
  watch: {
    initialData: {
      immediate: true,
      handler(data) {
        if (data) {
          this.form = {
            title: data.title || "",
            expires_at: data.expires_at ? dayjs(data.expires_at).toDate() : null,
            link_type: data.link_type || "NONE",
            product_id: data.product_id || null,
            shop_id: data.shop_id || null,
            external_url: data.external_url || "",
            is_active: data.is_active ?? true,
            is_quick_category: data.is_quick_category ?? false,
            custom_checkboxes: parseCustomCheckboxes(data.custom_checkboxes),
            thumbnailImage: data.thumbnailImage || null,
            images: data.images || [],
          };
        }
      },
    },
  },
  methods: {
    validate() {
      this.errors = {};
      
      if (!this.form.title || !this.form.title.trim()) {
        this.errors.title = "Название обязательно";
      } else if (this.form.title.length > 255) {
        this.errors.title = "Максимум 255 символов";
      }
      
      if (!this.form.expires_at) {
        this.errors.expires_at = "Дата окончания обязательна";
      } else if (dayjs(this.form.expires_at).isBefore(dayjs())) {
        this.errors.expires_at = "Дата должна быть в будущем";
      }
      
      if (!this.form.thumbnailImage) {
        this.errors.thumbnailImage = "Превью изображение обязательно";
      }
      
      if (!this.form.images || this.form.images.length === 0) {
        this.errors.images = "Добавьте хотя бы одно изображение для слайдера";
      }
      
      if (this.form.link_type === "EXTERNAL" && this.form.external_url) {
        try {
          new URL(this.form.external_url);
        } catch {
          this.errors.external_url = "Некорректный URL";
        }
      }
      
      return Object.keys(this.errors).length === 0;
    },
    submit() {
      if (!this.validate()) return;
      
      const data = {
        title: this.form.title.trim(),
        expires_at: dayjs(this.form.expires_at).format("YYYY-MM-DD HH:mm:ss"),
        link_type: this.form.link_type,
        is_active: this.form.is_active,
        is_quick_category: this.form.is_quick_category,
        custom_checkboxes: JSON.stringify(this.form.custom_checkboxes || {}),
        thumbnail_image: this.formatImage(this.form.thumbnailImage),
        images: this.form.images.map(img => this.formatImage(img)),
      };
      
      if (this.form.link_type === "PRODUCT" && this.form.product_id) {
        data.product_id = this.form.product_id;
      }
      if (this.form.link_type === "SHOP" && this.form.shop_id) {
        data.shop_id = this.form.shop_id;
      }
      if (this.form.link_type === "EXTERNAL" && this.form.external_url) {
        data.external_url = this.form.external_url;
      }
      
      this.$emit("submit", data);
    },
    formatImage(img) {
      if (!img) return null;
      return {
        path: img.path,
        origin_name: img.origin_name,
        alt: img.alt,
        size: img.size,
        type: img.type,
      };
    },
    onThumbnailUpload() {
      // Callback after thumbnail upload
    },
  },
};
</script>

<style lang="stylus">
.story-form {
  &__grid {
    display grid
    grid-template-columns 1fr 400px
    gap 32px
    
    +below(1200px) {
      grid-template-columns 1fr
    }
  }
  
  &__main {
    display flex
    flex-direction column
    gap 24px
  }
  
  &__media {
    display flex
    flex-direction column
    gap 24px
  }
  
  &__field {
    &--toggle {
      display flex
      align-items center
      justify-content space-between
    }
  }
  
  &__label {
    display block
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    margin-bottom 8px
    
    .required {
      color var(--red)
    }
  }
  
  &__input {
    width 100%
    padding 12px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem
    outline none
    transition border-color 0.2s
    
    &:focus {
      border-color var(--pink)
    }
    
    &--error {
      border-color var(--red)
    }
  }
  
  &__error {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--red)
  }
  
  &__toggle {
    display flex
    align-items center
    gap 12px
    cursor pointer
    
    input {
      display none
    }
  }
  
  &__toggle-slider {
    width 48px
    height 24px
    background var(--gray-dark)
    border-radius 24px
    position relative
    transition background 0.2s
    
    &::after {
      content ''
      position absolute
      width 20px
      height 20px
      background var(--white)
      border-radius 50%
      top 2px
      left 2px
      transition left 0.2s
    }
    
    input:checked + & {
      background var(--green)
      
      &::after {
        left 26px
      }
    }
  }
  
  &__toggle-text {
    font-size 0.875rem
    color var(--dark)
  }
  
  &__thumbnail {
    width 169px
    height 300px
    
    .image-upload__container {
      width 100%
      height 100%
    }
  }
  
  &__actions {
    display flex
    justify-content flex-end
    gap 16px
    margin-top 32px
    padding-top 24px
    border-top 1px solid var(--gray-dark)
  }
}
</style>
